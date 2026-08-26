<?php

namespace App\Console\Commands;

use App\Services\Competition\PreflightCheck;
use App\Services\Competition\PreflightService;
use Illuminate\Console\Command;

/**
 * The competition-day check.
 *
 * READ-ONLY. Every statement behind it is a SELECT, so it is safe to run at any
 * moment — including while contestants are mid-exam — without changing what it
 * is measuring.
 *
 * Exit codes are what a launch script reads:
 *   0  PASS, or WARNING without --strict
 *   1  FAIL, or WARNING with --strict
 */
class MadadPreflight extends Command
{
    protected $signature = 'madad:preflight
                            {--strict : Treat warnings as failures for the exit code}
                            {--json= : Also write the full report to this path as JSON}';

    protected $description = 'Read-only competition-day readiness check (PASS / WARNING / FAIL)';

    public function handle(PreflightService $preflight): int
    {
        // The settings singleton, resolved by the service itself. A missing
        // row is a FAIL inside the report rather than a crash here: an operator
        // running preflight on a broken install must still get a report.
        $report = $preflight->run();

        $this->render($report->checks);

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("report written to {$path}");
        }

        $this->newLine();
        $verdict = $report->verdict();

        // Blockers and warnings are reported separately, and in that order, so
        // an operator reading the tail of the output sees what stops launch
        // before what merely deserves a decision.
        foreach ($report->failures() as $check) {
            $this->error("BLOCKER  [{$check->area}] {$check->name}: {$check->detail}");
        }

        foreach ($report->warnings() as $check) {
            $this->warn("WARNING  [{$check->area}] {$check->name}: {$check->detail}");
        }

        $this->newLine();

        match ($verdict) {
            PreflightCheck::PASS => $this->info('VERDICT: PASS'),
            PreflightCheck::WARNING => $this->warn(sprintf('VERDICT: WARNING (%d warnings, no blockers)', count($report->warnings()))),
            default => $this->error(sprintf('VERDICT: FAIL (%d blockers)', count($report->failures()))),
        };

        if (! $report->passed()) {
            return self::FAILURE;
        }

        return $verdict === PreflightCheck::WARNING && $this->option('strict')
            ? self::FAILURE
            : self::SUCCESS;
    }

    /** @param  list<PreflightCheck>  $checks */
    private function render(array $checks): void
    {
        $area = null;

        foreach ($checks as $check) {
            if ($check->area !== $area) {
                $area = $check->area;
                $this->newLine();
                $this->line("<options=bold>{$area}</>");
            }

            $label = str_pad($check->status, 7);

            $this->line(match ($check->status) {
                PreflightCheck::PASS => "  <fg=green>{$label}</> {$check->name}: {$check->detail}",
                PreflightCheck::WARNING => "  <fg=yellow>{$label}</> {$check->name}: {$check->detail}",
                default => "  <fg=red>{$label}</> {$check->name}: {$check->detail}",
            });
        }
    }
}
