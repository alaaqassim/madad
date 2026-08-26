<?php

namespace App\Services\Competition;

use App\Exceptions\ExamException;
use App\Models\Competition;
use App\Models\CompetitionQuestion;
use App\Models\CompetitionUser;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The exam engine — Array + Index state machine.
 *
 *   not_started ──start──▶ in_progress ──last answer / time elapsed──▶ completed
 *
 * A contestant's paper is one randomised array of competition_questions ids on
 * their participation row, and their position is a zero-based index into it.
 * There is no assignment table and no stored per-question deadline.
 *
 * Four invariants hold everywhere in this class:
 *
 *  1. The clock is the server's. started_at anchors the timeline and
 *     current_question_started_at records when the contestant reached their
 *     current position; nothing the client sends about timing is read.
 *  2. Time never pauses. A disconnect, a logout, a closed browser or a different
 *     device changes nothing: elapsed windows are reconciled forward on the next
 *     request and the positions they covered are permanently spent.
 *  3. The index only ever moves forward. reconcile() takes a max(), so no path
 *     through this class can move a contestant backwards.
 *  4. Ownership is structural. Every participation is looked up FROM the
 *     authenticated user, never from a request parameter.
 *
 * The window for a position is capped at seconds_per_question. A contestant who
 * answers early advances immediately, but inherits a fresh full window rather
 * than the remainder of a longer timeline slot — see deadlineFor().
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
     * Start the exam, or resume it exactly where the timeline says it is.
     *
     * Both paths are the same call on purpose: a client that cannot tell the
     * difference cannot be tricked into restarting anything. Resume never
     * reshuffles and never grants a fresh window for a position whose time has
     * already passed.
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

        DB::transaction(function () use ($participation, $competition): void {
            // Serialises this contestant's concurrent start requests. The second
            // one finds the order already persisted and reuses it.
            $locked = CompetitionUser::query()
                ->whereKey($participation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->orders->ensureOrder($locked, $competition);

            if ($locked->exam_status === CompetitionUser::EXAM_NOT_STARTED) {
                $now = now();

                $locked->forceFill([
                    'exam_status' => CompetitionUser::EXAM_IN_PROGRESS,
                    'started_at' => $now,
                    'current_question' => 0,
                    'current_question_started_at' => $now,
                ]);
            }

            $locked->save();

            // A contestant resuming after an absence is moved forward here.
            $this->reconcile($locked, $competition);

            $participation->setRawAttributes($locked->getAttributes(), true);
        });

        return $participation;
    }

    /**
     * The question awaiting an answer, as a contestant-safe payload.
     *
     * Reading is still a state change, because reconciling elapsed time is: a
     * contestant who walks away does not come back to the question they left.
     *
     * @return array<string, mixed>|null null before the exam starts and once it is over
     */
    public function currentQuestion(CompetitionUser $participation, Competition $competition): ?array
    {
        $this->gate->assertMayParticipate($competition);

        if (! $participation->isInProgress()) {
            return null;
        }

        return DB::transaction(function () use ($participation, $competition) {
            $locked = CompetitionUser::query()
                ->whereKey($participation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->reconcile($locked, $competition);
            $participation->setRawAttributes($locked->getAttributes(), true);

            if ($locked->isCompleted()) {
                return null;
            }

            return $this->payloadFor($locked, $competition);
        });
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
        Competition $competition,
        ?int $questionId,
        string $option,
    ): array {
        $this->gate->assertMayParticipate($competition);

        $outcome = DB::transaction(function () use ($participation, $competition, $questionId, $option) {
            $locked = CompetitionUser::query()
                ->whereKey($participation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isCompleted()) {
                throw ExamException::examCompleted();
            }

            if (! $locked->isInProgress()) {
                // Answering before starting is not a thing the UI can do, and is
                // indistinguishable from any other unavailable question.
                return ['refuse' => 'question_not_available'];
            }

            $this->reconcile($locked, $competition);
            $participation->setRawAttributes($locked->getAttributes(), true);

            if ($locked->isCompleted()) {
                // The elapsed timeline finished the exam while this request was
                // in flight. Signalled by return value, not by throwing, so the
                // reconciliation just written survives the commit.
                return ['completed' => true];
            }

            $index = (int) $locked->current_question;
            $expectedId = $locked->questionIdAt($index);

            if ($expectedId === null) {
                $this->finalize($locked, $competition);

                return ['completed' => true];
            }

            if ($questionId !== null && $questionId !== $expectedId) {
                $position = array_search($questionId, $locked->order(), true);

                // A position already passed with nothing recorded is a window
                // that closed under the contestant — worth saying so, because
                // it is the one case where they lost something. A position they
                // already answered, a position ahead of them, and a question
                // that is not on their paper at all are refused identically:
                // telling them apart would map out other contestants' papers.
                return [
                    'refuse' => $position !== false && $position < $index && $locked->answerAt($position) === null
                        ? 'question_expired'
                        : 'question_not_available',
                ];
            }

            // reconcile() has already advanced past any closed window, so the
            // window is open. Re-checked anyway: an exception here would roll
            // back the very timeout it is reporting.
            if (now()->greaterThan($this->deadlineFor($locked, $competition, $index))) {
                $this->advance($locked, $competition, null, false);

                return ['refuse' => 'question_expired'];
            }

            $question = CompetitionQuestion::query()->findOrFail($expectedId);

            $this->advance($locked, $competition, $option, $option === $question->correct_option);
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
     * competitions.show_result decides whether the score is included. The
     * decision is made here, server-side — a frontend that forgets to hide it
     * cannot leak what it was never sent.
     *
     * @return array<string, mixed>
     */
    public function result(CompetitionUser $participation, Competition $competition): array
    {
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
     * Move the contestant to the position the server clock says they are at.
     *
     * Two independent derivations, and the later of them wins:
     *
     *   by chain    how many whole windows have closed since they arrived at
     *               their current position — this is the one that matters, and
     *               it is what makes a fast contestant's position stick
     *   by timeline floor((now - started_at) / seconds_per_question), the fixed
     *               global grid, kept as a floor so no contestant can ever end
     *               up behind where the wall clock puts them
     *
     * Positions passed over are spent: their answer marks stay '-' forever. The
     * contestant is never moved backwards, and never given back a window.
     */
    private function reconcile(CompetitionUser $participation, Competition $competition): void
    {
        if (! $participation->isInProgress()) {
            return;
        }

        $seconds = max(1, (int) $competition->seconds_per_question);
        $count = (int) $competition->question_count;
        $now = now();

        $index = (int) $participation->current_question;
        $startedAt = $participation->started_at ?? $now;
        $arrivedAt = $participation->current_question_started_at ?? $startedAt;

        $sinceArrival = $arrivedAt->diffInMilliseconds($now, false) / 1000;
        $byChain = $index + ($sinceArrival > 0 ? (int) floor($sinceArrival / $seconds) : 0);

        $sinceStart = $startedAt->diffInMilliseconds($now, false) / 1000;
        $byTimeline = $sinceStart > 0 ? (int) floor($sinceStart / $seconds) : 0;

        $target = min($count, max($index, $byChain, $byTimeline));

        if ($target <= $index) {
            return;
        }

        if ($target >= $count) {
            $this->finalize($participation, $competition);

            return;
        }

        // Both candidates are in the past; the earlier one is the conservative
        // choice because it grants the contestant less remaining time, never
        // more. (The chain candidate is provably never the later of the two.)
        $chainArrival = $arrivedAt->copy()->addSeconds(($target - $index) * $seconds);
        $timelineArrival = $startedAt->copy()->addSeconds($target * $seconds);

        $participation->forceFill([
            'answers' => $this->markSkipped($participation, $count, $index, $target),
            'current_question' => $target,
            'current_question_started_at' => $chainArrival->lessThan($timelineArrival) ? $chainArrival : $timelineArrival,
        ])->save();
    }

    /**
     * Record the answer (or its absence) and step to the next position.
     *
     * Aggregates are incremented here and recomputed authoritatively in
     * finalize(), so a live score is cheap and the stored result is exact.
     */
    private function advance(
        CompetitionUser $participation,
        Competition $competition,
        ?string $option,
        bool $isCorrect,
    ): void {
        $count = (int) $competition->question_count;
        $index = (int) $participation->current_question;

        $answers = $this->paddedAnswers($participation, $count);
        $answers[$index] = $option ?? CompetitionUser::NO_ANSWER;

        $participation->forceFill([
            'answers' => $answers,
            'current_question' => $index + 1,
            'current_question_started_at' => now(),
            'answered_questions' => $participation->answered_questions + ($option === null ? 0 : 1),
            'correct_answers' => $participation->correct_answers + ($isCorrect ? 1 : 0),
        ]);

        if ($index + 1 >= $count) {
            $this->finalize($participation, $competition);

            return;
        }

        $participation->save();
    }

    /**
     * Close the exam.
     *
     * Aggregates are recomputed from the answer string rather than trusted from
     * counters incremented per request, so the stored result cannot drift from
     * the answers it summarises. Safe to call repeatedly.
     */
    private function finalize(CompetitionUser $participation, Competition $competition): void
    {
        $count = (int) $competition->question_count;
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

    /**
     * The moment the current position closes.
     *
     * The fixed timeline gives each index the window
     * [started_at + i*s, started_at + (i+1)*s). A contestant who answers early
     * arrives before their slot opens, so that window alone would hand them more
     * than s seconds. Capping at arrival + s is what keeps the promise that no
     * question ever receives more than seconds_per_question — and because
     * arrival is never later than the slot start, the cap is always the binding
     * one. The slot end is kept as a hard ceiling regardless.
     */
    private function deadlineFor(CompetitionUser $participation, Competition $competition, int $index): Carbon
    {
        $seconds = max(1, (int) $competition->seconds_per_question);
        $startedAt = $participation->started_at ?? now();
        $arrivedAt = $participation->current_question_started_at ?? $startedAt;

        $capped = $arrivedAt->copy()->addSeconds($seconds);
        $slotEnd = $startedAt->copy()->addSeconds(($index + 1) * $seconds);

        return $capped->lessThan($slotEnd) ? $capped : $slotEnd;
    }

    /**
     * The contestant-safe payload for the current position.
     *
     * `sequence` is 1-based for display; `question_order` itself never leaves
     * the server, and neither does the answer key.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(CompetitionUser $participation, Competition $competition): array
    {
        $index = (int) $participation->current_question;
        $questionId = $participation->questionIdAt($index);

        if ($questionId === null) {
            throw ExamException::noCurrentQuestion();
        }

        $question = CompetitionQuestion::query()->findOrFail($questionId);

        $seconds = max(1, (int) $competition->seconds_per_question);
        $expiresAt = $this->deadlineFor($participation, $competition, $index);
        $now = now();

        return $question->toContestantPayload() + [
            'sequence' => $index + 1,
            'total_questions' => $competition->question_count,
            'opened_at' => $this->iso($participation->current_question_started_at),
            'expires_at' => $this->iso($expiresAt),
            'server_time' => $this->iso($now),
            'seconds_remaining' => min(
                (float) $seconds,
                max(0, $now->diffInMilliseconds($expiresAt, false) / 1000),
            ),
        ];
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
