<?php

namespace App\Services\Competition;

use App\Exceptions\ExamException;
use App\Models\CompetitionQuestion;
use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The exam engine — Array + Index over a fixed timeline.
 *
 *   not_started ──Begin──▶ in_progress ──last answer / time elapsed──▶ completed
 *
 * A contestant's paper is one randomised array of competition_questions ids on
 * their participation row, and their position is a zero-based index into it.
 * There is no assignment table, and no per-question timestamp of any kind.
 *
 * ─── THE ONE TIMING MODEL ───────────────────────────────────────────────────
 * `started_at` is the only clock reference that exists. Everything else is
 * arithmetic performed on the request that reports it:
 *
 *     slot i          [ started_at + i·s , started_at + (i+1)·s )
 *     time_index      floor( (now − started_at) / s )
 *     effective_end   min( started_at + personal_duration , settings.ends_at )
 *     expires_at      min( slot_end , effective_end )
 *
 * Nothing is stored about when a contestant arrived at a position, when they
 * disconnected, when they logged out, or when a question was opened. There is
 * no persisted expires_at to drift, and no arrival to reset.
 *
 * Five invariants hold everywhere in this class:
 *
 *  1. The clock is the server's. Nothing the client sends about timing is read.
 *  2. Time never pauses. A disconnect, a logout, a closed browser or a second
 *     device changes nothing: elapsed slots are reconciled forward on the next
 *     request and the positions they covered are permanently spent.
 *  3. The index only ever moves forward. reconcile() takes a max(), so no path
 *     through this class can move a contestant backwards.
 *  4. current_question ≤ time_index + 1, always. The index reaches time_index by
 *     reconciliation and time_index + 1 by answering the live slot, and there is
 *     no third way to move it — which is why a contestant who answers early
 *     waits at most one slot for the next one to open.
 *  5. Ownership is structural. Every participation is looked up FROM the
 *     authenticated user, never from a request parameter.
 *
 * State changes take a row lock on the participation, which serialises a
 * contestant's own concurrent requests across tabs and devices; that is what
 * makes a double-submit, or an answer racing its own expiry, resolve to one
 * outcome.
 */
class CompetitionExamService
{
    public function __construct(
        private readonly CompetitionGate $gate,
        private readonly QuestionOrderService $orders,
    ) {}

    /**
     * The contestant's participation, or null.
     *
     * Derived from the authenticated user. There is no variant of this that
     * accepts a participation id from the request.
     */
    public function participationFor(User $user): ?CompetitionUser
    {
        return CompetitionUser::query()->where('user_id', $user->id)->first();
    }

    /**
     * Begin the exam, or resume it exactly where the timeline says it is.
     *
     * Both paths are the same call on purpose: a client that cannot tell the
     * difference cannot be tricked into restarting anything. Resume never
     * reshuffles, never moves started_at, and never grants back a slot whose
     * time has already passed.
     */
    public function startOrResume(User $user, CompetitionSettings $settings): CompetitionUser
    {
        $this->gate->assertMayParticipate($settings);

        $participation = $this->participationFor($user);

        if ($participation === null) {
            throw ExamException::notAContestant();
        }

        if (! $participation->hasAccount()) {
            throw ExamException::accountNotProvisioned();
        }

        if ($participation->isCompleted()) {
            return $participation;
        }

        DB::transaction(function () use ($participation, $settings): void {
            // Serialises this contestant's concurrent Begin requests. The second
            // one finds the order and started_at already persisted and reuses them.
            $locked = CompetitionUser::query()
                ->whereKey($participation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->orders->ensureOrder($locked, $settings);

            // A first start is `not_started` AND no started_at. Index 0 alone
            // does not mean "fresh": a contestant who has pressed Begin and not
            // yet answered sits at index 0 with the clock already running.
            if ($locked->isNotStarted()) {
                $locked->forceFill([
                    'exam_status' => CompetitionUser::EXAM_IN_PROGRESS,
                    'started_at' => now(),
                    'current_question' => 0,
                ]);
            }

            $locked->save();

            // A contestant resuming after an absence is moved forward here.
            $this->reconcile($locked, $settings);

            $participation->setRawAttributes($locked->getAttributes(), true);
        });

        return $participation;
    }

    /**
     * The contestant's whole exam state, as the API envelope.
     *
     * Reading is still a state change, because reconciling elapsed time is: a
     * contestant who walks away does not come back to the question they left.
     *
     * Exactly one of `question` and `waiting` is ever non-null, and both are
     * null once the exam is over or before it has begun.
     *
     * @return array{exam_status: string, started_at: string|null, question: array<string, mixed>|null, waiting: array<string, mixed>|null}
     */
    public function state(CompetitionUser $participation, CompetitionSettings $settings): array
    {
        $this->gate->assertMayParticipate($settings);

        if (! $participation->isInProgress()) {
            return $this->envelope($participation, null, null);
        }

        return DB::transaction(function () use ($participation, $settings) {
            $locked = CompetitionUser::query()
                ->whereKey($participation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->reconcile($locked, $settings);
            $participation->setRawAttributes($locked->getAttributes(), true);

            if ($locked->isCompleted()) {
                return $this->envelope($locked, null, null);
            }

            $index = (int) $locked->current_question;

            // The contestant answered early and the next slot has not opened.
            if (now()->lessThan($this->opensAt($locked, $settings, $index))) {
                return $this->envelope($locked, null, $this->waitingPayload($locked, $settings, $index));
            }

            return $this->envelope($locked, $this->payloadFor($locked, $settings, $index), null);
        });
    }

    /**
     * The question awaiting an answer, or null.
     *
     * A thin read over state(): null here means "no question is live", which
     * covers not started, finished, and waiting for the next fixed slot alike.
     *
     * @return array<string, mixed>|null
     */
    public function currentQuestion(CompetitionUser $participation, CompetitionSettings $settings): ?array
    {
        return $this->state($participation, $settings)['question'];
    }

    /**
     * Record an answer at the contestant's current position.
     *
     * The client chooses an option and nothing else. It may name the question it
     * believes it is answering, but that is only a consistency check — the
     * position, the real question id, correctness, timing and score are all
     * resolved here from server state.
     *
     * @param  int|null  $questionId  what the client believes it is answering
     * @return array<string, mixed>
     */
    public function submitAnswer(
        CompetitionUser $participation,
        CompetitionSettings $settings,
        ?int $questionId,
        string $option,
    ): array {
        $this->gate->assertMayParticipate($settings);

        $outcome = DB::transaction(function () use ($participation, $settings, $questionId, $option) {
            $locked = CompetitionUser::query()
                ->whereKey($participation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isCompleted()) {
                throw ExamException::examCompleted();
            }

            if (! $locked->isInProgress()) {
                // Answering before Begin is not a thing the UI can do, and is
                // indistinguishable from any other unavailable question.
                return ['refuse' => 'question_not_available'];
            }

            $this->reconcile($locked, $settings);
            $participation->setRawAttributes($locked->getAttributes(), true);

            if ($locked->isCompleted()) {
                // The elapsed timeline, the personal duration or the window
                // finished the exam while this request was in flight. Signalled
                // by return value, not by throwing, so the reconciliation just
                // written survives the commit.
                return ['completed' => true];
            }

            $index = (int) $locked->current_question;
            $expectedId = $locked->questionIdAt($index);

            if ($expectedId === null) {
                $this->finalize($locked, $settings);

                return ['completed' => true];
            }

            // Answered early, and the next slot has not opened yet. There is
            // nothing live to answer.
            if (now()->lessThan($this->opensAt($locked, $settings, $index))) {
                return ['refuse' => 'question_not_available'];
            }

            if ($questionId !== null && $questionId !== $expectedId) {
                $position = array_search($questionId, $locked->order(), true);

                // A position already passed with nothing recorded is a slot that
                // closed under the contestant — worth saying so, because it is
                // the one case where they lost something. A position they
                // already answered, a position ahead of them, and a question
                // that is not on their paper at all are refused identically:
                // telling them apart would map out other contestants' papers.
                return [
                    'refuse' => $position !== false && $position < $index && $locked->answerAt($position) === null
                        ? 'question_expired'
                        : 'question_not_available',
                ];
            }

            // reconcile() has already advanced past any closed slot, so the slot
            // is open. Re-checked anyway: an exception here would roll back the
            // very timeout it is reporting.
            if (now()->greaterThan($this->deadlineFor($locked, $settings, $index))) {
                $this->advance($locked, $settings, null, false);

                return ['refuse' => 'question_expired'];
            }

            $question = CompetitionQuestion::query()->findOrFail($expectedId);

            $this->advance($locked, $settings, $option, $option === $question->correct_option);
            $participation->setRawAttributes($locked->getAttributes(), true);

            return [
                'accepted' => true,
                'sequence' => $index + 1,
                // Whether the answer was right is deliberately NOT returned:
                // it would turn the exam into an answer-key oracle.
                'exam_status' => $locked->exam_status,
            ];
        });

        // Refusals are raised after the commit, never inside the transaction:
        // an exception in there would roll back the very reconciliation that
        // explains the refusal.
        if ($outcome['completed'] ?? false) {
            throw ExamException::examCompleted();
        }

        if (($outcome['refuse'] ?? null) === 'question_expired') {
            throw ExamException::questionExpired();
        }

        if (($outcome['refuse'] ?? null) === 'question_not_available') {
            throw ExamException::questionNotAvailable();
        }

        return $outcome;
    }

    /**
     * The contestant's own result.
     *
     * competition_settings.show_result decides whether the score is included.
     * The decision is made here, server-side — a frontend that forgets to hide
     * it cannot leak what it was never sent.
     *
     * @return array<string, mixed>
     */
    public function result(CompetitionUser $participation, CompetitionSettings $settings): array
    {
        $payload = [
            'exam_status' => $participation->exam_status,
            'completed_at' => $this->iso($participation->completed_at),
            'show_result' => $settings->show_result,
        ];

        if (! $settings->show_result || ! $participation->isCompleted()) {
            return $payload;
        }

        return $payload + [
            'correct_answers' => $participation->correct_answers,
            'answered_questions' => $participation->answered_questions,
            'total_questions' => $settings->questionCount(),
        ];
    }

    // ───────────────────────────────────────────────────────── internals ────

    /**
     * Move the contestant to the position the server clock says they are at,
     * and end the exam if their time is up.
     *
     * The position is the later of where they are and where the wall clock puts
     * them:  target = max(current_question, floor((now − started_at) / s)).
     * Positions passed over are spent: their answer marks stay '-' forever.
     *
     * The exam ends when ANY of three things is true — the paper is used up,
     * the personal duration has run out, or the availability window has closed.
     * The last two are one test, because effective_end is their minimum.
     */
    private function reconcile(CompetitionUser $participation, CompetitionSettings $settings): void
    {
        if (! $participation->isInProgress()) {
            return;
        }

        $seconds = $settings->secondsPerQuestion();
        $count = $settings->questionCount();
        $now = now();

        $index = (int) $participation->current_question;
        $startedAt = $participation->started_at ?? $now;
        $effectiveEnd = $settings->effectiveEndFor($startedAt);

        $elapsed = max(0.0, $startedAt->diffInMilliseconds($now, false) / 1000);
        $timeIndex = (int) floor($elapsed / $seconds);

        $target = min($count, max($index, $timeIndex));

        // Out of time, or out of paper.
        if ($now->greaterThanOrEqualTo($effectiveEnd) || $target >= $count) {
            $this->finalize($participation, $settings);

            return;
        }

        // Waiting for a slot that opens only after their exam ends. No further
        // question can ever become live, so the wait is really the finish.
        if ($index > $timeIndex
            && $this->opensAt($participation, $settings, $index)->greaterThanOrEqualTo($effectiveEnd)) {
            $this->finalize($participation, $settings);

            return;
        }

        if ($target <= $index) {
            return;
        }

        $participation->forceFill([
            'answers' => $this->markSkipped($participation, $count, $index, $target),
            'current_question' => $target,
        ])->save();
    }

    /**
     * Record the answer (or its absence) and step to the next position.
     *
     * Nothing about the moment of the answer is stored. The next position's
     * window is its own fixed slot, decided by started_at alone — answering
     * early does not shift it, and answering late does not extend it.
     *
     * Aggregates are incremented here and recomputed authoritatively in
     * finalize(), so a live score is cheap and the stored result is exact.
     */
    private function advance(
        CompetitionUser $participation,
        CompetitionSettings $settings,
        ?string $option,
        bool $isCorrect,
    ): void {
        $count = $settings->questionCount();
        $index = (int) $participation->current_question;

        $answers = $this->paddedAnswers($participation, $count);
        $answers[$index] = $option ?? CompetitionUser::NO_ANSWER;

        $participation->forceFill([
            'answers' => $answers,
            'current_question' => $index + 1,
            'answered_questions' => $participation->answered_questions + ($option === null ? 0 : 1),
            'correct_answers' => $participation->correct_answers + ($isCorrect ? 1 : 0),
        ]);

        if ($index + 1 >= $count) {
            $this->finalize($participation, $settings);

            return;
        }

        $participation->save();
    }

    /**
     * Close the exam.
     *
     * Aggregates are recomputed from the answer string rather than trusted from
     * counters incremented per request, so the stored result cannot drift from
     * the answers it summarises. Only answers that were actually recorded count
     * — a position the clock took is a '-' and scores nothing. Safe to call
     * repeatedly; completed_at never moves once set.
     */
    private function finalize(CompetitionUser $participation, CompetitionSettings $settings): void
    {
        $count = $settings->questionCount();
        $order = $participation->order();

        $participation->forceFill([
            'answers' => $this->paddedAnswers($participation, $count),
            'current_question' => $count,
        ]);

        $correctByQuestionId = $order === []
            ? []
            : CompetitionQuestion::query()
                ->whereIn('id', $order)
                ->pluck('correct_option', 'id')
                ->all();

        $correct = 0;
        $answered = 0;

        foreach ($order as $position => $questionId) {
            $given = $participation->answerAt($position);

            if ($given === null) {
                continue;
            }

            $answered++;

            if (($correctByQuestionId[$questionId] ?? null) === $given) {
                $correct++;
            }
        }

        $participation->forceFill([
            'correct_answers' => $correct,
            'answered_questions' => $answered,
            'exam_status' => CompetitionUser::EXAM_COMPLETED,
            'completed_at' => $participation->completed_at ?? now(),
        ])->save();
    }

    /** The moment slot $index opens: started_at + index · seconds_per_question. */
    private function opensAt(CompetitionUser $participation, CompetitionSettings $settings, int $index): Carbon
    {
        return ($participation->started_at ?? now())
            ->copy()
            ->addSeconds($index * $settings->secondsPerQuestion());
    }

    /**
     * The moment slot $index closes.
     *
     * The slot's own end, unless the contestant's exam ends first — a late
     * starter whose window shuts at 11:00 does not get to answer until 11:00:20
     * merely because their slot runs that far. No time is granted beyond
     * effective_end, ever.
     */
    private function deadlineFor(CompetitionUser $participation, CompetitionSettings $settings, int $index): Carbon
    {
        $startedAt = $participation->started_at ?? now();

        $slotEnd = $startedAt->copy()->addSeconds(($index + 1) * $settings->secondsPerQuestion());
        $effectiveEnd = $settings->effectiveEndFor($startedAt);

        return $slotEnd->lessThan($effectiveEnd) ? $slotEnd : $effectiveEnd;
    }

    /**
     * The contestant-safe payload for a live position.
     *
     * `sequence` is 1-based for display; `question_order` itself never leaves
     * the server, and neither does the answer key. `opened_at` and `expires_at`
     * are DERIVED here — the API still reports them, but nothing persists them.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(CompetitionUser $participation, CompetitionSettings $settings, int $index): array
    {
        $questionId = $participation->questionIdAt($index);

        if ($questionId === null) {
            throw ExamException::noCurrentQuestion();
        }

        $question = CompetitionQuestion::query()->findOrFail($questionId);

        $expiresAt = $this->deadlineFor($participation, $settings, $index);
        $now = now();

        return $question->toContestantPayload() + [
            'sequence' => $index + 1,
            'total_questions' => $settings->questionCount(),
            'opened_at' => $this->iso($this->opensAt($participation, $settings, $index)),
            'expires_at' => $this->iso($expiresAt),
            'server_time' => $this->iso($now),
            'seconds_remaining' => $this->secondsBetween($now, $expiresAt, $settings),
        ];
    }

    /**
     * The transition between a slot answered early and the next one opening.
     *
     * Carries no question and no options — there is nothing live to answer yet.
     * `sequence` is the position about to open, so the client can keep showing
     * honest progress while it waits, and the wait can never exceed one slot
     * (invariant 4).
     *
     * @return array<string, mixed>
     */
    private function waitingPayload(CompetitionUser $participation, CompetitionSettings $settings, int $index): array
    {
        $opensAt = $this->opensAt($participation, $settings, $index);
        $now = now();

        return [
            'sequence' => $index + 1,
            'total_questions' => $settings->questionCount(),
            'opens_at' => $this->iso($opensAt),
            'server_time' => $this->iso($now),
            'seconds_remaining' => $this->secondsBetween($now, $opensAt, $settings),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $question
     * @param  array<string, mixed>|null  $waiting
     * @return array{exam_status: string, started_at: string|null, question: array<string, mixed>|null, waiting: array<string, mixed>|null}
     */
    private function envelope(CompetitionUser $participation, ?array $question, ?array $waiting): array
    {
        return [
            'exam_status' => $participation->exam_status,
            'started_at' => $this->iso($participation->started_at),
            'question' => $question,
            'waiting' => $waiting,
        ];
    }

    /**
     * Seconds from now until a moment: never negative, and never more than one
     * question's worth. Both bounds already hold by construction; clamping here
     * means no payload can advertise more time than the rules allow even if a
     * future caller gets the arithmetic wrong.
     */
    private function secondsBetween(Carbon $now, Carbon $moment, CompetitionSettings $settings): float
    {
        $seconds = $now->diffInMilliseconds($moment, false) / 1000;

        return min((float) $settings->secondsPerQuestion(), max(0.0, $seconds));
    }

    /** The answer string, guaranteed to be exactly $count characters. */
    private function paddedAnswers(CompetitionUser $participation, int $count): string
    {
        $answers = (string) $participation->answers;

        return str_pad(substr($answers, 0, $count), $count, CompetitionUser::NO_ANSWER);
    }

    /** Positions [$from, $to) are marked spent. Idempotent — they are already '-'. */
    private function markSkipped(CompetitionUser $participation, int $count, int $from, int $to): string
    {
        $answers = $this->paddedAnswers($participation, $count);

        for ($position = $from; $position < $to; $position++) {
            $answers[$position] = CompetitionUser::NO_ANSWER;
        }

        return $answers;
    }

    private function iso(?Carbon $moment): ?string
    {
        return $moment?->toIso8601String();
    }
}
