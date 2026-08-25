<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Services\Competition\CompetitionGate;
use Illuminate\Console\Command;

/**
 * Requirement 5 — open and close the portal through database state.
 *
 * Operational only. There is no HTTP route that changes competition status, so
 * the portal cannot be opened or closed by anything reachable from the public
 * internet.
 */
class MadadCompetitionStatus extends Command
{
    protected $signature = 'madad:status
                            {competition : Competition id}
                            {status? : One of draft|ready|open|closed. Omit to read the current value}';

    protected $description = 'Read or set the competition status that gates the portal';

    public function handle(CompetitionGate $gate): int
    {
        $competition = Competition::query()->find($this->argument('competition'));

        if ($competition === null) {
            $this->error('Competition not found.');

            return self::FAILURE;
        }

        $requested = $this->argument('status');

        if ($requested === null) {
            $this->line("status: {$competition->status} (portal open: ".($competition->isOpen() ? 'yes' : 'no').')');

            return self::SUCCESS;
        }

        if (! in_array($requested, Competition::STATUSES, true)) {
            $this->error('Status must be one of: '.implode(', ', Competition::STATUSES));

            return self::INVALID;
        }

        $previous = $competition->status;

        match ($requested) {
            Competition::STATUS_OPEN => $gate->open($competition),
            Competition::STATUS_CLOSED => $gate->close($competition),
            default => $competition->forceFill(['status' => $requested])->save(),
        };

        $this->info("status: {$previous} -> {$competition->fresh()->status}");

        if ($requested === Competition::STATUS_CLOSED) {
            // Stated plainly because it follows from the approved rule and
            // surprises people: closing stops in-flight contestants too.
            $this->warn('Closing also blocks contestants who are mid-exam from resuming.');
        }

        return self::SUCCESS;
    }
}
