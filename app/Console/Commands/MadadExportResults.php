<?php

namespace App\Console\Commands;

use App\Models\CompetitionSettings;
use App\Services\Competition\ResultExporter;
use App\Services\Competition\ResultService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Requirement 6 — extract results and the Top 100.
 *
 * Ordering is by correct_answers DESC only. If the cutoff falls inside a group
 * of tied scores the command says so loudly, because who takes the last place
 * is an unresolved business decision, not something this command may invent.
 *
 * Read-only against the competition data: it selects and writes a file. It
 * never updates a row and never stores a rank.
 */
class MadadExportResults extends Command
{
    protected $signature = 'madad:results
                            {--top=100 : How many contestants to return. 0 = every completed contestant}
                            {--export= : Write a UTF-8 (BOM) CSV to this path — the file the doctor opens in Excel}
                            {--json= : Write the raw payload to this path as JSON}';

    protected $description = 'Extract completed results and the Top N contestants';

    public function handle(ResultService $results, ResultExporter $exporter): int
    {
        $competition = CompetitionSettings::current();

        if ($competition === null) {
            $this->error('No competition_settings row exists. Run the migrations first.');

            return self::FAILURE;
        }

        $top = (int) $this->option('top');
        $csvPath = $this->option('export');

        try {
            $payload = $csvPath
                ? $exporter->export($competition, (string) $csvPath, $top)
                : $results->topN($competition, $top);
        } catch (Throwable $e) {
            $this->error('Export failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($csvPath) {
            $this->info("CSV written to {$csvPath} (UTF-8 with BOM, Excel/Arabic safe).");
        }

        if ($jsonPath = $this->option('json')) {
            file_put_contents($jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("JSON written to {$jsonPath}");
        }

        if (! $csvPath && ! $this->option('json')) {
            $this->table(
                ['#', 'name', 'email', 'correct', 'answered', 'duration'],
                collect($payload['rows'])->values()->map(fn ($r, $i) => [
                    $i + 1,
                    $r['name'],
                    $r['email'],
                    $r['correct_answers'],
                    $r['answered_questions'],
                    $r['duration_seconds'] === null ? '—' : gmdate('i:s', $r['duration_seconds']),
                ])->all(),
            );
        }

        $this->newLine();
        $this->line("completed: {$payload['total_completed']}   returned: {$payload['returned']}   cutoff score: ".($payload['cutoff_score'] ?? '—'));
        $this->line("ordered by: {$payload['ordered_by']}   tie-break rule: ".($payload['tie_break_rule'] ?? 'NONE (undecided)'));

        // How the boundary was actually settled, in the operator's own terms.
        if ($payload['cutoff_score'] !== null && $payload['contestants_tied_at_cutoff'] > 1) {
            $this->line(sprintf(
                'cutoff: %d contestants scored %d; separated by duration, and the last place went to an attempt of %s.',
                $payload['contestants_tied_at_cutoff'],
                $payload['cutoff_score'],
                $payload['cutoff_duration_seconds'] === null ? '—' : gmdate('i:s', $payload['cutoff_duration_seconds']),
            ));
        }

        if ($payload['cutoff_is_contested']) {
            $this->newLine();
            $this->warn(sprintf('WARNING: Top-%d cutoff cannot be settled by the tie-break.', $payload['limit']));
            $this->warn(sprintf(
                'CUTOFF CONTESTED: %d contestant(s) outside this list match the last place on BOTH score (%d) and '
                .'duration (%s). The faster-wins rule cannot separate them, so the boundary of this list is NOT '
                .'decided. A business ruling is required.',
                $payload['contestants_indistinguishable_at_cutoff'],
                $payload['cutoff_score'],
                $payload['cutoff_duration_seconds'] === null ? '—' : gmdate('i:s', $payload['cutoff_duration_seconds']),
            ));
        }

        return self::SUCCESS;
    }
}
