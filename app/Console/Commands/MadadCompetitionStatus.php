<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\CompetitionUser;
use App\Services\Competition\CompetitionGate;
use App\Services\Competition\PreflightService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Requirement 5 — inspect the competition, and open or close the portal.
 *
 * Operational only. There is no HTTP route that changes competition status, so
 * the portal cannot be opened or closed by anything reachable from the public
 * internet.
 *
 * ─── WHY --set AND NOT A POSITIONAL ARGUMENT ────────────────────────────────
 * Reading the state must be the thing that happens when an operator is unsure.
 * With no --set, this command only reports; a state change requires naming both
 * the action and the target value explicitly, so there is no shape of this
 * command that changes anything by accident and nothing that toggles.
 *
 * ─── THE TWO GUARDS ─────────────────────────────────────────────────────────
 *  → open   runs the full preflight first and refuses on any blocker.
 *  → closed is terminal — it ends the competition for everyone, including
 *           contestants who are mid-exam — so it requires an explicit
 *           confirmation, or --force when there is no terminal to confirm at.
 */
class MadadCompetitionStatus extends Command
{
    protected $signature = 'madad:status
                            {competition? : Competition id. Omit when there is only one}
                            {--set= : Change the status. One of draft|ready|open|closed}
                            {--force : Confirm a state change without being asked (required for non-interactive use)}';

    protected $description = 'Inspect the competition, and open or close the portal';

    public function handle(CompetitionGate $gate, PreflightService $preflight): int
    {
        $competition = $this->resolveCompetition();

        if ($competition === null) {
            return self::FAILURE;
        }

        $this->report($competition);

        $requested = $this->option('set');

        if ($requested === null) {
            return self::SUCCESS;
        }

        if (! in_array($requested, Competition::STATUSES, true)) {
            $this->error('--set must be one of: '.implode(', ', Competition::STATUSES));

            return self::INVALID;
        }

        if ($requested === $competition->status) {
            $this->newLine();
            $this->line("status is already {$requested}; nothing to do.");

            return self::SUCCESS;
        }

        return match ($requested) {
            Competition::STATUS_OPEN => $this->open($competition, $gate, $preflight),
            Competition::STATUS_CLOSED => $this->close($competition, $gate),
            default => $this->setPlainly($competition, $requested),
        };
    }

    // ──────────────────────────────────────────────────────────── reading ────

    private function report(Competition $competition): void
    {
        $counts = DB::table('competition_users')
            ->where('competition_id', $competition->id)
            ->selectRaw('exam_status, COUNT(*) c')
            ->groupBy('exam_status')
            ->pluck('c', 'exam_status');

        $total = (int) $counts->sum();

        $this->table(['field', 'value'], [
            ['competition', "#{$competition->id}  {$competition->name}"],
            ['status', $competition->status],
            ['portal open', $competition->isOpen() ? 'yes' : 'no'],
            ['question_count', (string) $competition->question_count],
            ['seconds_per_question', (string) $competition->seconds_per_question],
            ['show_result', $competition->show_result ? 'true' : 'false'],
            ['contestants (total)', (string) $total],
            ['  not_started', (string) ($counts[CompetitionUser::EXAM_NOT_STARTED] ?? 0)],
            ['  in_progress', (string) ($counts[CompetitionUser::EXAM_IN_PROGRESS] ?? 0)],
            ['  completed', (string) ($counts[CompetitionUser::EXAM_COMPLETED] ?? 0)],
            ['questions in bank', (string) DB::table('competition_questions')->where('competition_id', $competition->id)->count()],
        ]);
    }

    // ──────────────────────────────────────────────────────── transitions ────

    /**
     * Opening runs the readiness check first. Blockers refuse the transition;
     * warnings are shown and do not, because no stated rule makes any of them a
     * launch blocker.
     */
    private function open(Competition $competition, CompetitionGate $gate, PreflightService $preflight): int
    {
        $this->newLine();
        $this->line('Running the readiness check before opening…');

        $report = $preflight->run($competition);

        foreach ($report->failures() as $check) {
            $this->error("BLOCKER  [{$check->area}] {$check->name}: {$check->detail}");
        }

        foreach ($report->warnings() as $check) {
            $this->warn("WARNING  [{$check->area}] {$check->name}: {$check->detail}");
        }

        if (! $report->passed()) {
            $this->newLine();
            $this->error(sprintf(
                'REFUSED: %d readiness blocker(s). The competition was NOT opened. Run `php artisan madad:preflight` for the full report.',
                count($report->failures()),
            ));

            return self::FAILURE;
        }

        $this->info('Readiness check: '.$report->verdict());

        if (! $this->confirmTransition($competition, Competition::STATUS_OPEN)) {
            return self::FAILURE;
        }

        $previous = $competition->status;
        $gate->open($competition);
        $this->announce($competition, Competition::STATUS_OPEN, $previous);

        return self::SUCCESS;
    }

    /**
     * Closing ENDS the competition. Under the confirmed business rule this also
     * stops contestants who are mid-exam from resuming, fetching another
     * question, or submitting another answer — so the number of people it will
     * cut off is stated before the question is asked.
     */
    private function close(Competition $competition, CompetitionGate $gate): int
    {
        $inProgress = DB::table('competition_users')
            ->where('competition_id', $competition->id)
            ->where('exam_status', CompetitionUser::EXAM_IN_PROGRESS)
            ->count();

        $this->newLine();
        $this->warn('CLOSING ENDS THE COMPETITION.');
        $this->warn('A closed competition blocks new starts AND stops in-progress contestants from resuming,');
        $this->warn('fetching another question, or submitting another answer. It is not a pause.');

        if ($inProgress > 0) {
            $this->error("{$inProgress} contestant(s) are mid-exam right now and will be cut off.");
        }

        if (! $this->confirmTransition($competition, Competition::STATUS_CLOSED)) {
            return self::FAILURE;
        }

        $previous = $competition->status;
        $gate->close($competition);
        $this->announce($competition, Competition::STATUS_CLOSED, $previous);

        return self::SUCCESS;
    }

    /** draft and ready gate nothing that is currently running. */
    private function setPlainly(Competition $competition, string $status): int
    {
        if (! $this->confirmTransition($competition, $status)) {
            return self::FAILURE;
        }

        $previous = $competition->status;
        $competition->forceFill(['status' => $status])->save();
        $this->announce($competition, $status, $previous);

        return self::SUCCESS;
    }

    /**
     * An interactive run asks. A non-interactive run (CI, cron, a piped shell)
     * cannot be asked, so it must carry --force — never a silent yes.
     */
    private function confirmTransition(Competition $competition, string $target): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->newLine();
            $this->error("REFUSED: changing status to {$target} needs confirmation, and this run is not interactive. Re-run with --force.");

            return false;
        }

        if ($this->confirm("Change competition #{$competition->id} from {$competition->status} to {$target}?", false)) {
            return true;
        }

        $this->line('Cancelled. Nothing was changed.');

        return false;
    }

    private function announce(Competition $competition, string $requested, string $previous): void
    {
        $this->newLine();
        $this->info("status: {$previous} -> {$competition->fresh()->status}");

        if ($requested === Competition::STATUS_CLOSED) {
            $this->warn('The competition has ended. In-progress contestants can no longer continue.');
        }
    }

    private function resolveCompetition(): ?Competition
    {
        $id = $this->argument('competition');

        if ($id !== null) {
            $competition = Competition::query()->find($id);

            if ($competition === null) {
                $this->error('Competition not found.');
            }

            return $competition;
        }

        $competitions = Competition::query()->orderBy('id')->get();

        if ($competitions->count() !== 1) {
            $this->error($competitions->isEmpty()
                ? 'No competition exists.'
                : 'More than one competition exists — name the one you mean.');

            return null;
        }

        return $competitions->first();
    }
}
