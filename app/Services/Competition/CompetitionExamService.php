<?php

namespace App\Services\Competition;

use App\Exceptions\ExamException;
use App\Models\Competition;
use App\Models\CompetitionQuestion;
use App\Models\CompetitionUser;
use App\Models\CompetitionUserQuestion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The exam engine.
 *
 * Three invariants hold everywhere in this class:
 *
 *  1. The clock is the server's. opened_at and expires_at are written once
 *     from server time and never extended; nothing the client sends about
 *     timing is read.
 *  2. The paper is the database's. The current question is DERIVED as the
 *     lowest non-terminal sequence — there is no stored pointer to drift, and
 *     no client-supplied sequence is trusted.
 *  3. Ownership is structural. Every participation is looked up FROM the
 *     authenticated user, never from a request parameter, so a contestant
 *     cannot address another contestant's row at all.
 *
 * State changes take a row lock on the participation, which serialises a
 * contestant's own concurrent requests — that is what makes a double-submit,
 * or an answer racing its own timeout, resolve to one outcome.
 */
class CompetitionExamService
{
    public function __construct(
        private readonly CompetitionGate $gate,
        private readonly PaperService $papers,
    ) {}

    /**
     * The contestant's participation in a competition, or null.
     *
     * Derived from the authenticated user. There is no variant of this that
     * accepts a participation id from the request.
     */
    public function participationFor(User $user, Competition $competition): ?CompetitionUser
    {
        return CompetitionUser::query()
            ->where('competition_id', $competition->id)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Start the exam, or resume it exactly where it was left.
     *
     * Both paths are the same call on purpose: a client that cannot tell the
     * difference cannot be tricked into restarting anything.
     */
    public function startOrResume(User $user, Competition $competition): CompetitionUser
    {
        $this->gate->assertMayParticipate($competition);

        $participation = $this->participationFor($user, $competition);

        if ($participation === null) {
            throw ExamException::notAContestant();
        }

        if (! $participation->hasAccount()) {
            throw ExamException::accountNotProvisioned();
        }

        if ($participation->isCompleted()) {
            return $participation;
        }

        DB::transaction(function () use ($participation) {
            // Serialises this contestant's concurrent start requests. The
            // second one finds the paper already built and reuses it.
            $locked = CompetitionUser::query()
                ->whereKey($participation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->papers->ensurePaper($locked);

            if ($locked->exam_status === CompetitionUser::EXAM_NOT_STARTED) {
                $locked->forceFill([
                    'exam_status' => CompetitionUser::EXAM_IN_PROGRESS,
                    'started_at' => now(),
                ])->save();
            }

            $participation->setRawAttributes($locked->getAttributes(), true);
        });

        return $participation;
    }

    /**
     * The question awaiting an answer, as a contestant-safe payload.
     *
     * Serving is a state change: an unopened question has its deadline written
     * here, once. Expired questions are swept to terminal first, so a
     * contestant who walks away does not come back to a stale live question.
     *
     * @return array<string, mixed>|null null once the paper is finished
     */
    public function currentQuestion(CompetitionUser $participation): ?array
    {
        $this->gate->assertMayParticipate($participation->competition()->firstOrFail());

        if ($participation->isCompleted()) {
            return null;
        }

        return DB::transaction(function () use ($participation) {
            $locked = CompetitionUser::query()
                ->whereKey($participation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $competition = $locked->competition()->firstOrFail();
            $row = $this->advanceToLiveQuestion($locked, $competition);

            if ($row === null) {
                $this->finalize($locked);
                $participation->setRawAttributes($locked->fresh()->getAttributes(), true);

                return null;
            }

            $question = CompetitionQuestion::query()->findOrFail($row->competition_question_id);

            return $question->toContestantPayload() + [
                'sequence' => $row->sequence,
                'total_questions' => $competition->question_count,
                'opened_at' => $this->iso($row->opened_at),
                'expires_at' => $this->iso($row->expires_at),
                'server_time' => $this->iso(now()),
                'seconds_remaining' => max(0, now()->diffInMilliseconds($row->expires_at, false) / 1000),
            ];
        });
    }

    /**
     * Record an answer.
     *
     * The client supplies only which question it believes it is answering and
     * which option it chose. Correctness, timing and score are computed here;
     * anything else in the request is ignored.
     *
     * @return array<string, mixed>
     */
    public function submitAnswer(CompetitionUser $participation, int $questionId, string $option): array
    {
        $this->gate->assertMayParticipate($participation->competition()->firstOrFail());

        $outcome = DB::transaction(function () use ($participation, $questionId, $option) {
            $locked = CompetitionUser::query()
                ->whereKey($participation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isCompleted()) {
                throw ExamException::examCompleted();
            }

            $competition = $locked->competition()->firstOrFail();

            // Resolve the named question WITHIN this contestant's own paper.
            // A question belonging to someone else, or one that never existed,
            // simply is not found here — the query is scoped to the row lock we
            // already hold, so there is nothing to substitute.
            $row = CompetitionUserQuestion::query()
                ->where('competition_user_id', $locked->id)
                ->where('competition_question_id', $questionId)
                ->first();

            // Not on this paper / already answered / already timed out / never
            // served — all indistinguishable to the caller, deliberately.
            if ($row === null || $row->isTerminal() || ! $row->hasBeenOpened()) {
                throw ExamException::questionNotAvailable();
            }

            // It must also be the question actually awaiting an answer, so an
            // earlier unanswered question cannot be skipped or back-filled.
            $currentSequence = CompetitionUserQuestion::query()
                ->where('competition_user_id', $locked->id)
                ->whereNull('answered_at')
                ->where('timed_out', false)
                ->min('sequence');

            if ($row->sequence !== (int) $currentSequence) {
                throw ExamException::questionNotAvailable();
            }

            // Server time is the only clock. No grace window: the business rule
            // is exactly seconds_per_question.
            //
            // The refusal is signalled by return value, not by throwing: an
            // exception here would roll back the very timeout we just recorded
            // and leave the question live for another late attempt.
            if (now()->greaterThan($row->expires_at)) {
                $this->markTimedOut($row);
                $this->finalizeIfComplete($locked, $competition);

                return ['expired' => true];
            }

            $question = CompetitionQuestion::query()->findOrFail($row->competition_question_id);
            $isCorrect = $option === $question->correct_option;

            $row->forceFill([
                'selected_option' => $option,
                'answered_at' => now(),
                'is_correct' => $isCorrect,
                'timed_out' => false,
            ])->save();

            $this->finalizeIfComplete($locked, $competition);

            return [
                'accepted' => true,
                'sequence' => $row->sequence,
                // Whether the answer was right is deliberately NOT returned:
                // it would turn the exam into an answer-key oracle.
                'exam_status' => $locked->fresh()->exam_status,
            ];
        });

        // Thrown after the commit so the recorded timeout survives.
        if ($outcome['expired'] ?? false) {
            throw ExamException::questionExpired();
        }

        return $outcome;
    }

    /**
     * The contestant's own result.
     *
     * competitions.show_result decides whether the score is included. The
     * decision is made here, server-side — a frontend that forgets to hide it
     * cannot leak what it was never sent.
     *
     * @return array<string, mixed>
     */
    public function result(CompetitionUser $participation): array
    {
        $competition = $participation->competition()->firstOrFail();

        $payload = [
            'exam_status' => $participation->exam_status,
            'completed_at' => $this->iso($participation->completed_at),
            'show_result' => $competition->show_result,
        ];

        if (! $competition->show_result || ! $participation->isCompleted()) {
            return $payload;
        }

        return $payload + [
            'correct_answers' => $participation->correct_answers,
            'answered_questions' => $participation->answered_questions,
            'total_questions' => $competition->question_count,
        ];
    }

    // ───────────────────────────────────────────────────────── internals ────

    /**
     * Returns the live question, sweeping any expired ones to terminal on the
     * way. Bounded by the paper size so a corrupt row cannot spin forever.
     */
    private function advanceToLiveQuestion(CompetitionUser $participation, Competition $competition): ?CompetitionUserQuestion
    {
        for ($guard = 0; $guard <= $competition->question_count; $guard++) {
            $row = CompetitionUserQuestion::query()
                ->where('competition_user_id', $participation->id)
                ->whereNull('answered_at')
                ->where('timed_out', false)
                ->orderBy('sequence')
                ->first();

            if ($row === null) {
                return null;
            }

            if (! $row->hasBeenOpened()) {
                // First service of this question: the deadline is fixed now and
                // never moves again, so a refresh cannot buy more time.
                $openedAt = now();

                $row->forceFill([
                    'opened_at' => $openedAt,
                    'expires_at' => $openedAt->copy()->addSeconds($competition->seconds_per_question),
                ])->save();

                return $row;
            }

            if (now()->lessThanOrEqualTo($row->expires_at)) {
                return $row;
            }

            $this->markTimedOut($row);
        }

        return null;
    }

    /** Idempotent by construction: a terminal row is never selected again. */
    private function markTimedOut(CompetitionUserQuestion $row): void
    {
        $row->forceFill([
            'timed_out' => true,
            'selected_option' => null,
            'answered_at' => null,
            'is_correct' => false,
        ])->save();
    }

    private function finalizeIfComplete(CompetitionUser $participation, Competition $competition): void
    {
        $outstanding = CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)
            ->whereNull('answered_at')
            ->where('timed_out', false)
            ->exists();

        if (! $outstanding) {
            $this->finalize($participation);
        }
    }

    /**
     * Recomputes the aggregates from the question rows rather than trusting
     * counters incremented per request, so the stored result cannot drift from
     * the paper it summarises. Safe to call repeatedly.
     */
    private function finalize(CompetitionUser $participation): void
    {
        $totals = CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)
            ->selectRaw('SUM(is_correct) AS correct, SUM(selected_option IS NOT NULL) AS answered')
            ->first();

        $participation->forceFill([
            'correct_answers' => (int) ($totals->correct ?? 0),
            'answered_questions' => (int) ($totals->answered ?? 0),
            'exam_status' => CompetitionUser::EXAM_COMPLETED,
            'completed_at' => $participation->completed_at ?? now(),
        ])->save();
    }

    private function iso(?Carbon $moment): ?string
    {
        return $moment?->toIso8601String();
    }
}
