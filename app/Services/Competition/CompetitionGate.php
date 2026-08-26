<?php

namespace App\Services\Competition;

use App\Exceptions\ExamException;
use App\Models\CompetitionSettings;

/**
 * The backend authority on whether the portal is usable.
 *
 * TWO conditions, both required, and neither can be satisfied by the client:
 *
 *   1. status = 'open'
 *   2. the server clock is inside the announced availability window
 *      [starts_at, ends_at)
 *
 * ─── WHY THE WINDOW NOW GATES ───────────────────────────────────────────────
 * It used to be documented as display metadata that must never gate access, on
 * the reasoning that one authority means one answer. The confirmed business
 * model replaces that: the competition is available 09:00 → 11:00, and a
 * contestant may only begin inside it. The two conditions do not contradict
 * each other — status is the operator's switch, the window is the schedule, and
 * BOTH must permit before anyone sits the exam. A NULL on either side of the
 * window means unbounded on that side, so an operator who sets no schedule is
 * back to status alone.
 *
 * ─── WHICH REFUSAL ──────────────────────────────────────────────────────────
 * The vocabulary is the existing one, and the distinction is whether waiting
 * could help:
 *
 *   before starts_at   → competition_not_open   (a retry may succeed later)
 *   after  ends_at     → competition_closed     (terminal, offer no retry)
 *   status closed      → competition_closed     (terminal)
 *   status draft/ready → competition_not_open
 *
 * Note the consequence of the approved rule: closing the portal, or the window
 * passing, also stops contestants who are mid-exam. That follows directly from
 * "closed → no start and no resume"; a carve-out for in-flight papers would be
 * a new business rule, so none has been invented here.
 */
class CompetitionGate
{
    public function mayParticipate(CompetitionSettings $settings): bool
    {
        return $settings->isOpen() && $settings->withinWindow();
    }

    public function assertMayParticipate(CompetitionSettings $settings): void
    {
        if ($this->mayParticipate($settings)) {
            return;
        }

        throw $this->hasEnded($settings)
            ? ExamException::competitionClosed()
            : ExamException::competitionNotOpen();
    }

    /**
     * Terminal: the competition is over and no later request can succeed.
     *
     * Either the operator closed it, or the announced window has passed.
     */
    public function hasEnded(CompetitionSettings $settings): bool
    {
        return $settings->isClosed() || $settings->windowHasEnded();
    }

    /** The reason code for the current state, or null when the portal is usable. */
    public function reason(CompetitionSettings $settings): ?string
    {
        return match (true) {
            $this->mayParticipate($settings) => null,
            $this->hasEnded($settings) => 'competition_closed',
            default => 'competition_not_open',
        };
    }

    public function open(CompetitionSettings $settings): void
    {
        $settings->forceFill(['status' => CompetitionSettings::STATUS_OPEN])->save();
    }

    public function close(CompetitionSettings $settings): void
    {
        $settings->forceFill(['status' => CompetitionSettings::STATUS_CLOSED])->save();
    }
}
