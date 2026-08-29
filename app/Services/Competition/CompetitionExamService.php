<?php

namespace App\Services\Competition;

use App\Exceptions\ExamException;
use App\Models\CompetitionQuestion;
use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The exam engine — Array + Index with immediate advance.
 *
 *   not_started ──Begin──▶ in_progress ──last answer / time elapsed──▶ completed
 *
 * A contestant's paper is one randomised array of competition_questions ids on
 * their participation row, and their position is a zero-based index into it.
 * There is no assignment table and no row per answer: the whole exam is one
 * contestant row.
 *
 * ─── THE ONE TIMING MODEL ───────────────────────────────────────────────────
 * Two anchors, both on that row, answering two different questions:
 *
 *     started_at                    when the ATTEMPT began — bounds the whole
 *     current_question_started_at   when the LIVE question became live
 *
 * and everything else is arithmetic performed on the request that reports it:
 *
 *     effective_end     min( started_at + personal_duration , settings.ends_at )
 *     expires_at        min( current_question_started_at + s , effective_end )
 *     windows_elapsed   floor( (now − current_question_started_at) / s )
 *
 * ANSWERING EARLY ADVANCES IMMEDIATELY. Answer five seconds in and the next
 * question is served at once with its own window of up to s — there is no wait
 * for a grid position, because there is no grid. That is precisely why the
 * second anchor has to be persisted: where the current window sits depends on
 * when each previous answer landed, and nothing else records that.
 *
 * TIME STILL NEVER PAUSES. A disconnect does not stop the question timer and
 * does not stop the attempt timer. On the next request reconcile() consumes one
 * whole window per position missed, advancing the anchor by exactly s each time
 * — never to `now`, which would hand back the disconnected seconds.
 *
 * Five invariants hold everywhere in this class:
 *
 *  1. The clock is the server's. Nothing the client sends about timing is read.
 *  2. Time never pauses. A disconnect, a logout, a closed browser or a second
 *     device changes nothing: elapsed windows are reconciled forward on the next
 *     request and the positions they covered are permanently spent.
 *  3. The index only ever moves forward, and only ever by one position per
 *     window consumed or answer given.
 *  4. After any reconciliation, current_question_started_at <= now < that anchor
 *     plus s, and now < effective_end. In other words: whatever the API is about
 *     to report as live really is live, on the server's clock.
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
     * reshuffles, never moves started_at, and never reopens the window of a
     * question whose time has already run out.
     */
    public function startOrResume(User $user, CompetitionSettings $settings): CompetitionUser
    {
        // Settled BEFORE the gate, and that ordering is the whole point: once
        // the window has passed the gate refuses every call, so a contestant it
        // cut off would never again reach the code that ends their exam.
        $participation = $this->participationFor($user);

        if ($participation !== null) {
            $this->settle($participation, $settings);
        }

        // The refusal order is unchanged: a closed portal answers `closed` to a
        // stranger, not `not a contestant`, exactly as before.
        $this->gate->assertMayParticipate($settings);

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
                $now = now();

                // Both anchors start together: at index 0 the attempt and the
                // first question begin at the same instant.
                $locked->forceFill([
                    'exam_status' => CompetitionUser::EXAM_IN_PROGRESS,
                    'started_at' => $now,
                    // Written once, from the one place the formula lives, at
                    // the only moment both its operands are known. Everything
                    // afterwards reads it; nothing recomputes it. It is what
                    // lets a results view see that a contestant's time is up
                    // without anything having to run when it does.
                    'effective_end_at' => $settings->effectiveEndFor($now),
                    'current_question' => 0,
                    'current_question_started_at' => $now,
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
     * An in-progress contestant ALWAYS has a live question here — reconcile()
     * has just guaranteed it. `question` is null only before Begin and after
     * the exam is over.
     *
     * @return array{exam_status: string, started_at: string|null, question: array<string, mixed>|null}
     */
    public function state(CompetitionUser $participation, CompetitionSettings $settings): array
    {
        $this->settle($participation, $settings);
        $this->gate->assertMayParticipate($settings);

        if (! $participation->isInProgress()) {
            return $this->envelope($participation, null);
        }

        return DB::transaction(function () use ($participation, $settings) {
            $locked = CompetitionUser::query()
                ->whereKey($participation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->reconcile($locked, $settings);
            $this->skipVanishedQuestions($locked, $settings);
            $locked->save();

            $participation->setRawAttributes($locked->getAttributes(), true);

            if ($locked->isCompleted()) {
                return $this->envelope($locked, null);
            }

            return $this->envelope(
                $locked,
                $this->payloadFor($locked, $settings, (int) $locked->current_question),
            );
        });
    }

    /**
     * The question awaiting an answer, or null.
     *
     * A thin read over state(): null here means "no question is live", which
     * covers not started and finished alike.
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
        $this->settle($participation, $settings);
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

            // Before the expected id is read, so a contestant standing on a
            // question that no longer exists is moved past it here rather than
            // meeting a 404 from findOrFail() below.
            $this->skipVanishedQuestions($locked, $settings);
            $locked->save();

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

            if ($questionId !== null && $questionId !== $expectedId) {
                $position = array_search($questionId, $locked->order(), true);

                // A position already passed with nothing recorded is a window
                // that closed under the contestant — worth saying so, because it
                // is the one case where they lost something. A position they
                // already answered, a position ahead of them, and a question
                // that is not on their paper at all are refused identically:
                // telling them apart would map out other contestants' papers.
                return [
                    'refuse' => $position !== false && $position < $index && $locked->answerAt($position) === null
                        ? 'question_expired'
                        : 'question_not_available',
                ];
            }

            // reconcile() has already consumed any closed window, so the live
            // one is open. Re-checked anyway, because the clock can tick between
            // the two reads: it consumes a whole window rather than throwing, so
            // the timeout it reports is the timeout it records.
            if (now()->greaterThan($this->deadlineFor($locked, $settings))) {
                $this->expireCurrent($locked, $settings);

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
     * Settle every contestant still mid-exam.
     *
     * ─── WHY THIS EXISTS ────────────────────────────────────────────────────
     * A contestant is normally settled by their own next request: answering the
     * last question finalises on the spot, and a returning contestant whose
     * time has run out is settled by settle() before the gate. Neither fires
     * for someone who closes the browser at question 59 and never comes back —
     * no request, no settlement — and every result surface filters on
     * `exam_status = completed`, so that contestant vanishes from their own
     * result and from the Top 100 with their answers sitting intact in the row.
     *
     * The confirmed rule is that NOBODY is left in progress once the exam is
     * over. This is the sweep that guarantees it.
     *
     * Two kinds of contestant are settled, and they are counted separately
     * because they mean different things to an operator:
     *
     *   expired    their own time ran out — the paper, the allowance or the
     *              window. reconcile() settles them at the moment it actually
     *              happened, which is what the duration tie-break needs.
     *   cut short  time had NOT run out; the competition was closed under them.
     *              Their exam ended AT the closure, so that is what is recorded.
     *
     * `$includeUnfinished` is what separates the two. False — the default — is
     * always safe: it only records something the rules already consider true.
     * True is for closing the competition, and it is irreversible, so the
     * caller is the one that must have asked.
     *
     * @param  bool  $includeUnfinished  settle contestants whose time has NOT run out
     * @param  bool  $dryRun  count what would happen and change nothing
     * @return array{settled: int, expired: int, cut_short: int, remaining: int}
     */
    public function settleAll(
        CompetitionSettings $settings,
        bool $includeUnfinished = false,
        bool $dryRun = false,
    ): array {
        $expired = 0;
        $cutShort = 0;

        CompetitionUser::query()
            ->where('exam_status', CompetitionUser::EXAM_IN_PROGRESS)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($settings, $includeUnfinished, $dryRun, &$expired, &$cutShort): void {
                foreach ($rows as $row) {
                    $isExpired = $row->started_at !== null
                        && now()->greaterThanOrEqualTo($settings->effectiveEndFor($row->started_at));

                    if (! $isExpired && ! $includeUnfinished) {
                        continue;
                    }

                    $isExpired ? $expired++ : $cutShort++;

                    if ($dryRun) {
                        continue;
                    }

                    DB::transaction(function () use ($row, $settings, $isExpired): void {
                        $locked = CompetitionUser::query()
                            ->whereKey($row->id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        if (! $locked->isInProgress()) {
                            return;   // settled by their own request in the meantime
                        }

                        // Expired: reconcile() is the only thing that knows
                        // whether the paper or the clock ended it, and records
                        // the moment accordingly. Cut short: the competition
                        // ended under them, so the closure IS the end.
                        $isExpired
                            ? $this->reconcile($locked, $settings)
                            : $this->finalize($locked, $settings, now());
                    });
                }
            });

        return [
            'settled' => $expired + $cutShort,
            'expired' => $expired,
            'cut_short' => $cutShort,
            'remaining' => CompetitionUser::query()
                ->where('exam_status', CompetitionUser::EXAM_IN_PROGRESS)
                ->count(),
        ];
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
        // The result surface deliberately does not go through the gate — a
        // contestant may read their own outcome after the portal shuts — which
        // makes it the one place a cut-off exam can still be settled.
        $this->settle($participation, $settings);

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
     * End an exam whose time is up, whether or not the portal will serve it.
     *
     * ─── WHY THIS IS NOT reconcile() ────────────────────────────────────────
     * reconcile() runs INSIDE the gate, so it settles a contestant whose own
     * sixty minutes ran out while the competition was still open. It can never
     * settle one the WINDOW ended: at that moment the gate refuses every call,
     * and a refused call never reaches reconcile(). Those contestants stayed
     * `in_progress` for ever — and because every result surface filters on
     * `exam_status = completed`, they disappeared from their own result, from
     * the Top 100 and from the CSV export, with their submitted answers sitting
     * intact in the database the whole time.
     *
     * So this runs BEFORE the gate on every entry point. The rule it enforces
     * is the confirmed one in its own words: `now >= effective_end` is
     * terminal, and effective_end is min(started_at + duration, ends_at).
     *
     * ⚠️ Time only. A manual `status = closed` before the window has passed is
     *    an operator's switch, not the passage of time, and settles nobody —
     *    finalising on it would invent a business rule and would freeze the
     *    scores of contestants an operator may yet reopen to.
     *
     * The unlocked pre-check matters. Without it every request would open a
     * transaction and take a row lock only to discover there was nothing to do;
     * with it, the cost on the hot path is the two attribute reads below.
     */
    private function settle(CompetitionUser $participation, CompetitionSettings $settings): void
    {
        if (! $participation->isInProgress() || $participation->started_at === null) {
            return;
        }

        if (now()->lessThan($settings->effectiveEndFor($participation->started_at))) {
            return;
        }

        DB::transaction(function () use ($participation, $settings): void {
            $locked = CompetitionUser::query()
                ->whereKey($participation->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Another tab or device may have settled it first; reconcile() is
             * idempotent and returns immediately for a row already completed.
             *
             * Deliberately reconcile() rather than finalize(): it is the one
             * place that knows whether the PAPER or the CLOCK ended this
             * attempt, and therefore the only one that can record the right
             * moment. Settling here with effective_end would mis-time every
             * contestant whose questions ran out before their hour did.
             */
            $this->reconcile($locked, $settings);

            $participation->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Consume every question window that has fully elapsed, and end the exam if
     * the contestant's time is up.
     *
     * The live question owns [ q0 , q0 + s ) where q0 is
     * current_question_started_at. If `now` is past that, the window closed
     * without an answer: the position is marked spent, the index steps on, and
     * the anchor moves forward by EXACTLY one window. Repeat until the window
     * containing `now` is reached.
     *
     *     windows = floor( (now − q0) / s )
     *     index  += windows          (capped at the paper length)
     *     q0     += windows · s      ← NOT `now`
     *
     * Advancing the anchor by whole windows rather than to `now` is the whole
     * disconnect rule in one line. A contestant who vanishes for 115 seconds
     * with 40-second windows loses two positions and rejoins 35 seconds into the
     * third, exactly as if they had been sitting there watching it run out.
     * Setting q0 = now would silently hand back the remainder.
     *
     * The exam ends when ANY of three things is true — the paper is used up, the
     * personal duration has run out, or the availability window has closed. The
     * last two are one test, because effective_end is their minimum.
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
        $anchor = $participation->questionStartedAt() ?? $startedAt;

        /*
         * Where this attempt runs out, and WHEN.
         *
         * Two things can end it and the earlier one wins: the paper (every
         * remaining position spending its window, one after another from the
         * live anchor) or the clock (the personal allowance, or the
         * availability window — effective_end is already their minimum).
         *
         * Computing the moment up front is what makes completed_at truthful.
         * Testing `now >= effective_end` first would blame the clock for an
         * exam the paper had finished an hour earlier, and since duration is
         * the tie-break that would cost the contestant places they had won.
         *
         * The condition is equivalent to the old one: now >= paper_end holds
         * exactly when the elapsed windows would have consumed the order.
         */
        $paperEnd = $anchor->copy()->addSeconds(max(0, $count - $index) * $seconds);
        $trueEnd = $paperEnd->lessThan($effectiveEnd) ? $paperEnd : $effectiveEnd;

        if ($now->greaterThanOrEqualTo($trueEnd)) {
            $this->finalize($participation, $settings, $trueEnd);

            return;
        }

        $elapsed = max(0.0, $anchor->diffInMilliseconds($now, false) / 1000);
        $windows = (int) floor($elapsed / $seconds);

        if ($windows <= 0) {
            return;
        }

        // Strictly inside the paper: now < paper_end, so the windows elapsed
        // cannot have consumed the order.
        $target = min($count, $index + $windows);

        $participation->forceFill([
            'answers' => $this->markSkipped($participation, $count, $index, $target),
            'current_question' => $target,
            'current_question_started_at' => $anchor->copy()->addSeconds(($target - $index) * $seconds),
        ])->save();
    }

    /**
     * Record the answer and open the next question IMMEDIATELY.
     *
     * The next question's window starts now — not at a grid position, not at the
     * end of the one just answered. Answering in five seconds means the next
     * question is live five seconds in with its own full window, which is the
     * whole point of the immediate-advance rule.
     *
     * The overall attempt is unaffected: effective_end still runs from
     * started_at, so answering fast buys questions, never minutes.
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
            'current_question_started_at' => now(),
            'answered_questions' => $participation->answered_questions + ($option === null ? 0 : 1),
            'correct_answers' => $participation->correct_answers + ($isCorrect ? 1 : 0),
        ]);

        if ($index + 1 >= $count) {
            // Finished by answering: the exam ended at this answer.
            $this->finalize($participation, $settings, now());

            return;
        }

        $participation->save();
    }

    /**
     * Close the live question unanswered and open the next one.
     *
     * The same arithmetic reconcile() uses for one window, kept separate because
     * the two are reached differently: reconcile() consumes windows a contestant
     * was absent for, this one resolves a submission that lost a race with its
     * own deadline. The anchor moves by exactly s either way — a timeout noticed
     * late must not extend the question that follows it.
     */
    /**
     * Step over any position whose question is no longer in the bank.
     *
     * A paper names question ids, and a question can be deleted after the paper
     * was dealt. Preflight calls that a blocker and it should never reach a
     * contestant - but "should never" is not a plan, and what happened without
     * this was the worst possible answer: findOrFail() raised a 404 from inside
     * the exam, so the contestant sat on an error screen unable to read the
     * question or answer it until the window timed out.
     *
     * Skipped rather than expired, and the distinction is deliberate. The
     * position is marked unanswered because nobody could have answered it, but
     * the clock is NOT charged for it: the next question opens now, with a full
     * window. A contestant must not lose forty seconds to our data problem.
     *
     * Bounded by the paper length so a bank emptied entirely cannot spin.
     */
    private function skipVanishedQuestions(CompetitionUser $participation, CompetitionSettings $settings): void
    {
        $count = $settings->questionCount();

        for ($guard = 0; $guard <= $count; $guard++) {
            $index = (int) $participation->current_question;

            if ($index >= $count) {
                return;
            }

            $questionId = $participation->questionIdAt($index);

            if ($questionId !== null && CompetitionQuestion::query()->whereKey($questionId)->exists()) {
                return;
            }

            Log::warning('Madad: a question on a live paper is missing from the bank', [
                'competition_user_id' => $participation->id,
                'position' => $index,
                'question_id' => $questionId,
            ]);

            $answers = $this->paddedAnswers($participation, $count);
            $answers[$index] = CompetitionUser::NO_ANSWER;

            $participation->forceFill([
                'answers' => $answers,
                'current_question' => $index + 1,
                'current_question_started_at' => now(),
            ]);

            if ($index + 1 >= $count) {
                $this->finalize($participation, $settings);

                return;
            }
        }
    }

    private function expireCurrent(CompetitionUser $participation, CompetitionSettings $settings): void
    {
        $count = $settings->questionCount();
        $index = (int) $participation->current_question;

        $answers = $this->paddedAnswers($participation, $count);
        $answers[$index] = CompetitionUser::NO_ANSWER;

        $anchor = ($participation->questionStartedAt() ?? now())
            ->copy()
            ->addSeconds($settings->secondsPerQuestion());

        $participation->forceFill([
            'answers' => $answers,
            'current_question' => $index + 1,
            'current_question_started_at' => $anchor,
        ]);

        if ($index + 1 >= $count) {
            // The paper ran out when this window closed.
            $this->finalize($participation, $settings, $anchor);

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
     *
     * ─── completed_at IS A RANKING INPUT ────────────────────────────────────
     * Under the confirmed tie-break, contestants level on score are separated
     * by how long they took: completed_at − started_at, shortest first. That
     * makes this timestamp load-bearing, so every caller passes the moment the
     * exam ACTUALLY ended — the last answer, the close of the last window, or
     * effective_end — rather than letting it default to "whenever a request
     * happened to notice". A contestant who walked away and reopened the page
     * two hours later would otherwise record a two-hour attempt and lose a tie
     * they should have won.
     *
     * @param  Carbon|null  $endedAt  the real end; clamped into [started_at, effective_end]
     */
    private function finalize(
        CompetitionUser $participation,
        CompetitionSettings $settings,
        ?Carbon $endedAt = null,
    ): void {
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

        // The anchor is cleared: a finished contestant has no live question, and
        // leaving a stale one would be a timestamp that means nothing.
        $participation->forceFill([
            'correct_answers' => $correct,
            'answered_questions' => $answered,
            'exam_status' => CompetitionUser::EXAM_COMPLETED,
            'current_question_started_at' => null,
            'completed_at' => $participation->completed_at ?? $this->endMoment($participation, $settings, $endedAt),
        ])->save();
    }

    /**
     * The moment an exam ended, bounded by its own attempt.
     *
     * A caller's candidate is trusted but never allowed outside
     * [started_at, effective_end]: an end before the start is impossible, and
     * one after effective_end would credit a contestant with time the rules
     * never gave them. Both bounds hold by construction today; clamping here
     * means a future caller cannot break the ranking by getting it wrong.
     */
    private function endMoment(
        CompetitionUser $participation,
        CompetitionSettings $settings,
        ?Carbon $endedAt,
    ): Carbon {
        $now = now();
        $startedAt = $participation->started_at ?? $now;
        $effectiveEnd = $settings->effectiveEndFor($startedAt);

        $end = ($endedAt ?? $now)->copy();

        if ($end->greaterThan($effectiveEnd)) {
            $end = $effectiveEnd->copy();
        }

        return $end->lessThan($startedAt) ? $startedAt->copy() : $end;
    }

    /**
     * The moment the live question became live.
     *
     * Read straight off the row — under immediate advance there is nothing to
     * derive it from, because it depends on when the previous answer landed.
     */
    private function openedAt(CompetitionUser $participation): Carbon
    {
        return ($participation->questionStartedAt() ?? now())->copy();
    }

    /**
     * The moment the live question closes.
     *
     * Its own window, unless the contestant's exam ends first — a late starter
     * whose availability window shuts at 11:00 does not get to answer until
     * 11:00:20 merely because their question opened at 10:59:40. No time is
     * granted beyond effective_end, ever.
     */
    private function deadlineFor(CompetitionUser $participation, CompetitionSettings $settings): Carbon
    {
        $questionEnd = $this->openedAt($participation)->addSeconds($settings->secondsPerQuestion());
        $effectiveEnd = $settings->effectiveEndFor($participation->started_at ?? now());

        return $questionEnd->lessThan($effectiveEnd) ? $questionEnd : $effectiveEnd;
    }

    /**
     * The contestant-safe payload for the live position.
     *
     * `sequence` is 1-based for display; `question_order` itself never leaves
     * the server, and neither does the answer key. `expires_at` is DERIVED here
     * from the anchor — the API reports it, but no expiry is ever persisted, so
     * there is no stored deadline that could drift or be extended.
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

        $expiresAt = $this->deadlineFor($participation, $settings);
        $now = now();

        return $question->toContestantPayload() + [
            'sequence' => $index + 1,
            'total_questions' => $settings->questionCount(),
            'opened_at' => $this->iso($this->openedAt($participation)),
            'expires_at' => $this->iso($expiresAt),
            'server_time' => $this->iso($now),
            'seconds_remaining' => $this->secondsBetween($now, $expiresAt, $settings),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $question
     * @return array{exam_status: string, started_at: string|null, question: array<string, mixed>|null}
     */
    private function envelope(CompetitionUser $participation, ?array $question): array
    {
        return [
            'exam_status' => $participation->exam_status,
            'started_at' => $this->iso($participation->started_at),
            'question' => $question,
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
