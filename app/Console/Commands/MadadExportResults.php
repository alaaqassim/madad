<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Services\Competition\ResultService;
use Illuminate\Console\Command;

/**
 * Requirement 6 — extract results and the Top 100.
 *
 * Ordering is by correct_answers DESC only. If the cutoff falls inside a group
 * of tied scores the command says so loudly, because who takes the last place
 * is an unresolved business decision, not something this command may invent.
 */
class MadadExportResults extends Command
{
    protected $signature = 'madad:results
                            {competition : Competition id}
                            {--top=100 : How many contestants to return}
                            {--json= : Write the payload to this path instead of the console}';

    protected $description = 'Extract completed results and the Top N contestants';

    public function handle(ResultService $results): int
    {
        $competition = Competition::query()->find($this->argument('competition'));

        if ($competition === null) {
            $this->error('Competition not found.');

            return self::FAILURE;
        }

        $payload = $results->topN($competition, (int) $this->option('top'));

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Written to {$path}");
        } else {
            $this->table(
                ['#', 'name', 'email', 'correct', 'answered'],
                collect($payload['rows'])->values()->map(fn ($r, $i) => [
                    $i + 1, $r['name'], $r['email'], $r['correct_answers'], $r['answered_questions'],
                ])->all(),
            );
        }

        $this->line("completed: {$payload['total_completed']}   returned: {$payload['returned']}   cutoff score: ".($payload['cutoff_score'] ?? '—'));

        if ($payload['cutoff_is_contested']) {
            $this->warn(sprintf(
                'CUTOFF CONTESTED: %d contestants share the cutoff score of %d, which is more than the places remaining. '
                .'No tie-break rule exists, so the boundary of this list is NOT decided. A business ruling is required.',
                $payload['contestants_tied_at_cutoff'],
                $payload['cutoff_score'],
            ));
        }

        return self::SUCCESS;
    }
}
