<?php

namespace App\Console\Commands;

use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Services\Competition\CompetitionExamService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Leave nobody mid-exam.
 *
 * ─── THE RULE THIS ENFORCES ─────────────────────────────────────────────────
 * Once the exam is over there must be no contestant in `in_progress`. Answering
 * the last question settles a contestant on the spot, and a returning
 * contestant whose time has run out is settled before the gate — but a
 * contestant who closes the browser at question 59 and never comes back sends
 * no request, so nothing ever settles them. Every result surface filters on
 * `exam_status = completed`, so they disappear from the Top 100 with their
 * answers intact in the row.
 *
 * This command is the sweep. It runs after the competition ends and before the
 * results are pulled, and it invents nothing: each contestant is finalised by
 * the same code their own request would have run.
 */
class MadadSettleExams extends Command
{
    protected $signature = 'madad:settle
                            {--dry-run : Report what would be settled and change nothing}
                            {--all : Also settle contestants whose time has NOT run out}
                            {--force : Skip confirmation}';

    protected $description = 'Finalise contestants left mid-exam, so nobody is in_progress when results are pulled';

    public function handle(CompetitionExamService $exam): int
    {
        $settings = CompetitionSettings::current();

        if ($settings === null) {
            $this->error('No competition settings row. Nothing to settle.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $all = (bool) $this->option('all');

        $preview = $exam->settleAll($settings, $all, dryRun: true);

        $this->newLine();
        $this->line("competition: {$settings->name}   status: {$settings->status}");

        if ($preview['settled'] === 0) {
            $this->info('Nothing to settle: no contestant is left mid-exam'.($all ? '.' : ' with their time run out.'));

            if (! $all && $preview['remaining'] > 0) {
                $this->warn(
                    "{$preview['remaining']} contestant(s) are still mid-exam but their time has NOT run out. "
                    .'They are still playing. Use --all only once the competition itself is over.'
                );
            }

            return self::SUCCESS;
        }

        $this->reportPreview($preview, $all);

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->confirmSweep($preview)) {
            return self::FAILURE;
        }

        $result = $exam->settleAll($settings, $all);

        $this->newLine();
        $this->info("Settled {$result['settled']} contestant(s): {$result['expired']} whose time had run out"
            .($result['cut_short'] > 0 ? ", {$result['cut_short']} cut short by the closure" : '').'.');

        if ($result['remaining'] > 0) {
            $this->warn("{$result['remaining']} contestant(s) are STILL mid-exam"
                .($all ? ' — they began while this command was running.' : ' because their time has not run out. Re-run with --all once the competition is over.'));
        } else {
            $this->info('No contestant is left in progress.');
        }

        return self::SUCCESS;
    }

    /**
     * What the sweep will do, and what it is worth — an operator deciding
     * whether to run an irreversible step deserves to see the scores it lets in.
     *
     * @param  array{settled: int, expired: int, cut_short: int, remaining: int}  $preview
     */
    private function reportPreview(array $preview, bool $all): void
    {
        $this->newLine();
        $this->line("to settle: {$preview['settled']}   (time run out: {$preview['expired']}"
            .($all ? ", cut short: {$preview['cut_short']}" : '').')');

        $pending = DB::table('competition_users')
            ->where('exam_status', CompetitionUser::EXAM_IN_PROGRESS)
            ->orderByDesc('correct_answers')
            ->limit(5)
            ->get(['contestant_email', 'correct_answers', 'answered_questions', 'current_question']);

        if ($pending->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('highest scores currently missing from the results:');
        $this->table(
            ['email', 'correct', 'answered', 'reached'],
            $pending->map(fn ($r) => [
                $r->contestant_email, $r->correct_answers, $r->answered_questions, $r->current_question,
            ])->all(),
        );
    }

    /** @param  array{settled: int, expired: int, cut_short: int, remaining: int}  $preview */
    private function confirmSweep(array $preview): bool
    {
        $this->newLine();
        $this->warn('Settling is IRREVERSIBLE. Each contestant is scored and closed, exactly as their own');
        $this->warn('final request would have done. It cannot be undone by re-opening the competition.');

        if ($preview['cut_short'] > 0) {
            $this->error("{$preview['cut_short']} of them still had time left and will be cut short at this moment.");
        }

        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('REFUSED: settling needs confirmation, and this run is not interactive. Re-run with --force.');

            return false;
        }

        if ($this->confirm("Settle {$preview['settled']} contestant(s)?", false)) {
            return true;
        }

        $this->warn('REFUSED: nothing was settled.');

        return false;
    }
}
