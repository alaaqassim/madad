<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A refusal the contestant is allowed to see.
 *
 * Every message here is safe to render: none of them reveal another
 * contestant's data, the answer key, or why a guess about someone else's row
 * failed. Anything that would leak is reported as a flat "not available".
 */
class ExamException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    public static function competitionNotOpen(): self
    {
        return new self('The competition is not open.', 'competition_not_open', 403);
    }

    public static function notAContestant(): self
    {
        return new self('You are not registered for this competition.', 'not_a_contestant', 403);
    }

    public static function accountNotProvisioned(): self
    {
        return new self('Your participation is not yet active.', 'account_not_provisioned', 403);
    }

    public static function examCompleted(): self
    {
        return new self('Your exam is already complete.', 'exam_completed', 409);
    }

    public static function noCurrentQuestion(): self
    {
        return new self('There is no question awaiting an answer.', 'no_current_question', 409);
    }

    /**
     * Deliberately identical whether the question belongs to someone else, does
     * not exist, or is not the contestant's current one. A distinguishable
     * message would turn this endpoint into an oracle for probing other papers.
     */
    public static function questionNotAvailable(): self
    {
        return new self('That question is not available.', 'question_not_available', 422);
    }

    public static function questionExpired(): self
    {
        return new self('The time for that question has expired.', 'question_expired', 422);
    }

    public static function paperNotReady(): self
    {
        return new self('The question bank is not ready for this competition.', 'paper_not_ready', 409);
    }
}
