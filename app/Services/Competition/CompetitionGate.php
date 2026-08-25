<?php

namespace App\Services\Competition;

use App\Exceptions\ExamException;
use App\Models\Competition;

/**
 * The single backend authority on whether the portal is usable.
 *
 * Only competitions.status is consulted. starts_at and ends_at are metadata
 * and are deliberately NOT read here — the whole point of one authoritative
 * column is that there is exactly one answer to "is it open?".
 *
 * draft / ready → nobody may start.
 * open          → an eligible contestant may start and resume.
 * closed        → no start and no resume.
 *
 * Note the consequence of the approved rule: closing the portal also stops
 * contestants who are mid-exam. That follows directly from "closed → no new
 * start/resume"; a carve-out for in-flight papers would be a new business
 * rule, so none has been invented here.
 */
class CompetitionGate
{
    public function mayParticipate(Competition $competition): bool
    {
        return $competition->isOpen();
    }

    /**
     * Refuses with the code that tells the client what it may do next:
     * `competition_closed` is terminal, `competition_not_open` may still change.
     * The refusal itself is identical either way.
     */
    public function assertMayParticipate(Competition $competition): void
    {
        if ($this->mayParticipate($competition)) {
            return;
        }

        throw $competition->isClosed()
            ? ExamException::competitionClosed()
            : ExamException::competitionNotOpen();
    }

    public function open(Competition $competition): void
    {
        $competition->forceFill(['status' => Competition::STATUS_OPEN])->save();
    }

    public function close(Competition $competition): void
    {
        $competition->forceFill(['status' => Competition::STATUS_CLOSED])->save();
    }
}
