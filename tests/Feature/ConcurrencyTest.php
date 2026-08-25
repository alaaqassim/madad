<?php

namespace Tests\Feature;

use App\Exceptions\ExamException;
use App\Models\Competition;
use App\Models\CompetitionQuestion;
use App\Models\CompetitionUser;
use App\Models\CompetitionUserQuestion;
use App\Services\Competition\CompetitionExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * Deliberate races against the exam engine.
 *
 * ─── WHAT THIS CAN AND CANNOT PROVE ─────────────────────────────────────────
 * PHPUnit runs in one process, and RefreshDatabase wraps each test in a
 * transaction whose rows a second connection could not even see. So this file
 * does NOT fire two real HTTP requests at the same instant. That limitation is
 * stated here rather than papered over.
 *
 * What it does instead is reproduce the state each racing request would
 * actually be holding — a MODEL INSTANCE READ BEFORE THE OTHER REQUEST WROTE —
 * and then let the engine resolve it. That is the real hazard: the engine's
 * defence is that every state change re-reads the row under `lockForUpdate`
 * inside a transaction and decides from that, never from the instance it was
 * handed. Feeding it a stale instance exercises exactly the branch a genuine
 * concurrent request would take once the lock released.
 *
 * ConcurrencyLockTest complements this with a real two-connection proof that
 * the row lock itself blocks, which is the piece a single connection cannot
 * demonstrate.
 */
class ConcurrencyTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private function service(): CompetitionExamService
    {
        return app(CompetitionExamService::class);
    }

    /** @return array{0: Competition, 1: CompetitionUser} */
    private function contestant(int $questions = 5): array
    {
        $competition = $this->makeCompetition(['question_count' => $questions, 'seconds_per_question' => 40]);
        $this->makeQuestions($competition, $questions);

        return [$competition, $this->makeContestant($competition)];
    }

    // ── 1. two simultaneous start requests ──────────────────────────────────

    public function test_two_simultaneous_starts_build_exactly_one_paper(): void
    {
        [$competition, $participation] = $this->contestant(5);
        $service = $this->service();

        // Both "requests" hold the participation as it was BEFORE either ran.
        $requestA = $participation->fresh();
        $requestB = $participation->fresh();

        $service->startOrResume($requestA->user, $competition);
        $service->startOrResume($requestB->user, $competition);

        $rows = CompetitionUserQuestion::query()->where('competition_user_id', $participation->id)->get();

        $this->assertCount(5, $rows, 'a second start must not build a second paper');
        $this->assertSame([1, 2, 3, 4, 5], $rows->pluck('sequence')->sort()->values()->all());
        $this->assertSame(5, $rows->pluck('competition_question_id')->unique()->count());
        $this->assertSame(CompetitionUser::EXAM_IN_PROGRESS, $participation->fresh()->exam_status);
    }

    public function test_a_second_start_does_not_reshuffle_or_restart(): void
    {
        [$competition, $participation] = $this->contestant(5);
        $service = $this->service();

        $service->startOrResume($participation->user, $competition);
        $order = CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)->orderBy('sequence')
            ->pluck('competition_question_id')->all();
        $startedAt = $participation->fresh()->started_at;

        $this->travel(30)->seconds();
        $service->startOrResume($participation->fresh()->user, $competition);

        $after = CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)->orderBy('sequence')
            ->pluck('competition_question_id')->all();

        $this->assertSame($order, $after, 'the persisted order must survive a second start');
        $this->assertEquals($startedAt, $participation->fresh()->started_at, 'started_at must be written once');
    }

    // ── 2. two answer submissions for the same question ─────────────────────

    public function test_two_submissions_for_the_same_question_resolve_to_one_answer(): void
    {
        [$competition, $participation] = $this->contestant(5);
        $service = $this->service();
        $service->startOrResume($participation->user, $competition);
        $question = $service->currentQuestion($participation->fresh());

        // Two requests that both read the participation while the question was
        // still live and unanswered.
        $requestA = $participation->fresh();
        $requestB = $participation->fresh();

        $first = $service->submitAnswer($requestA, $question['question_id'], 'A');
        $this->assertTrue($first['accepted']);

        try {
            $service->submitAnswer($requestB, $question['question_id'], 'B');
            $this->fail('the second submission must not be accepted');
        } catch (ExamException $e) {
            $this->assertSame('question_not_available', $e->reason);
        }

        $row = CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)->where('sequence', 1)->first();

        // The first answer stands; the loser of the race changes nothing.
        $this->assertSame('A', $row->selected_option);
        $this->assertNotNull($row->answered_at);
        $this->assertFalse($row->timed_out);
        $this->assertSame(1, CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)->whereNotNull('answered_at')->count());
    }

    // ── 3. answer versus timeout at the deadline ────────────────────────────

    public function test_an_answer_arriving_after_the_deadline_loses_to_the_timeout(): void
    {
        [$competition, $participation] = $this->contestant(3);
        $service = $this->service();
        $service->startOrResume($participation->user, $competition);
        $question = $service->currentQuestion($participation->fresh());

        // The request was in flight as the deadline passed.
        $stale = $participation->fresh();
        $this->travel(41)->seconds();

        try {
            $service->submitAnswer($stale, $question['question_id'], 'A');
            $this->fail('an answer past the deadline must not be accepted');
        } catch (ExamException $e) {
            $this->assertSame('question_expired', $e->reason);
        }

        $row = CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)->where('sequence', 1)->first();

        // The timeout must have been COMMITTED even though the call threw —
        // otherwise the question would still be live for another late attempt.
        $this->assertTrue($row->timed_out);
        $this->assertNull($row->selected_option);
        $this->assertFalse($row->is_correct);
    }

    public function test_an_answer_on_the_last_millisecond_is_accepted_and_cannot_then_time_out(): void
    {
        [$competition, $participation] = $this->contestant(3);
        $service = $this->service();
        $service->startOrResume($participation->user, $competition);
        $question = $service->currentQuestion($participation->fresh());

        $row = CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)->where('sequence', 1)->first();

        // Sit exactly on the deadline: inside the window, by one millisecond.
        $this->travelTo($row->expires_at);

        $outcome = $service->submitAnswer($participation->fresh(), $question['question_id'], 'A');
        $this->assertTrue($outcome['accepted']);

        // A sweep arriving immediately afterwards must not overwrite it.
        $this->travel(5)->seconds();
        $service->currentQuestion($participation->fresh());

        $row->refresh();
        $this->assertSame('A', $row->selected_option);
        $this->assertFalse($row->timed_out, 'an accepted answer must never be reclassified as a timeout');
    }

    // ── 4. refresh while the question opens ─────────────────────────────────

    public function test_simultaneous_refreshes_open_the_question_exactly_once(): void
    {
        [$competition, $participation] = $this->contestant(4);
        $service = $this->service();
        $service->startOrResume($participation->user, $competition);

        // Three "requests" that all believe the question has never been served.
        $a = $service->currentQuestion($participation->fresh());
        $b = $service->currentQuestion($participation->fresh());
        $c = $service->currentQuestion($participation->fresh());

        $this->assertSame($a['question_id'], $b['question_id']);
        $this->assertSame($b['question_id'], $c['question_id']);
        $this->assertSame($a['opened_at'], $c['opened_at'], 'opened_at is written once');
        $this->assertSame($a['expires_at'], $c['expires_at'], 'a refresh must not extend the deadline');

        $this->assertSame(1, CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)
            ->whereNotNull('opened_at')->count(), 'only the current question may be opened');
    }

    // ── 5. closing the competition while a contestant is active ─────────────

    public function test_closing_mid_flight_stops_the_contestant_without_corrupting_the_paper(): void
    {
        [$competition, $participation] = $this->contestant(5);
        $service = $this->service();
        $service->startOrResume($participation->user, $competition);
        $question = $service->currentQuestion($participation->fresh());

        // The contestant's request was already in flight when the operator
        // closed the competition.
        $inFlight = $participation->fresh();
        $competition->forceFill(['status' => Competition::STATUS_CLOSED])->save();

        foreach ([
            fn () => $service->currentQuestion($inFlight),
            fn () => $service->submitAnswer($inFlight, $question['question_id'], 'A'),
            fn () => $service->startOrResume($inFlight->user, $competition->fresh()),
        ] as $call) {
            try {
                $call();
                $this->fail('a closed competition must refuse every continuation');
            } catch (ExamException $e) {
                $this->assertSame('competition_closed', $e->reason);
            }
        }

        $row = CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)->where('sequence', 1)->first();

        $this->assertNull($row->selected_option);
        $this->assertFalse($row->timed_out);
        $this->assertSame(CompetitionUser::EXAM_IN_PROGRESS, $participation->fresh()->exam_status);
    }

    // ── 6. repeated finalisation ────────────────────────────────────────────

    public function test_repeated_finalisation_does_not_move_the_score_or_the_finish_line(): void
    {
        [$competition, $participation] = $this->contestant(3);
        $service = $this->service();
        $service->startOrResume($participation->user, $competition);

        $expected = 0;

        for ($i = 0; $i < 3; $i++) {
            $question = $service->currentQuestion($participation->fresh());
            $key = CompetitionQuestion::query()->find($question['question_id'])->correct_option;
            $expected++;
            $service->submitAnswer($participation->fresh(), $question['question_id'], $key);
        }

        $completedAt = $participation->fresh()->completed_at;

        // Several concurrent readers all arriving at a finished paper.
        $this->travel(2)->minutes();

        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($service->currentQuestion($participation->fresh()));
        }

        $final = $participation->fresh();
        $this->assertEquals($completedAt, $final->completed_at, 'completed_at must be written once');
        $this->assertSame($expected, $final->correct_answers);
        $this->assertSame(3, $final->answered_questions);
        $this->assertSame(CompetitionUser::EXAM_COMPLETED, $final->exam_status);
    }

    public function test_a_stale_request_cannot_answer_a_paper_that_has_already_finished(): void
    {
        [$competition, $participation] = $this->contestant(1);
        $service = $this->service();
        $service->startOrResume($participation->user, $competition);
        $question = $service->currentQuestion($participation->fresh());

        // Captured while the exam was still in progress.
        $stale = $participation->fresh();

        $service->submitAnswer($participation->fresh(), $question['question_id'], 'A');
        $this->assertSame(CompetitionUser::EXAM_COMPLETED, $participation->fresh()->exam_status);

        try {
            $service->submitAnswer($stale, $question['question_id'], 'B');
            $this->fail('a completed exam must refuse a late submission');
        } catch (ExamException $e) {
            // The lock re-read sees `completed`, not the stale `in_progress`.
            $this->assertSame('exam_completed', $e->reason);
        }

        $this->assertSame('A', CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)->where('sequence', 1)->value('selected_option'));
    }

    public function test_two_contestants_racing_each_other_keep_separate_papers_and_scores(): void
    {
        [$competition, $first] = $this->contestant(4);
        $second = $this->makeContestant($competition);
        $service = $this->service();

        $service->startOrResume($first->user, $competition);
        $service->startOrResume($second->user, $competition);

        // Interleave their requests, as two contestants at adjacent desks would.
        for ($i = 0; $i < 4; $i++) {
            foreach ([$first, $second] as $index => $who) {
                $question = $service->currentQuestion($who->fresh());
                $key = CompetitionQuestion::query()->find($question['question_id'])->correct_option;
                $wrong = collect(CompetitionQuestion::OPTIONS)->reject(fn ($o) => $o === $key)->first();

                // The first contestant answers correctly, the second does not.
                $service->submitAnswer($who->fresh(), $question['question_id'], $index === 0 ? $key : $wrong);
            }
        }

        $this->assertSame(4, $first->fresh()->correct_answers);
        $this->assertSame(0, $second->fresh()->correct_answers);
        $this->assertSame(4, $first->fresh()->answered_questions);
        $this->assertSame(4, $second->fresh()->answered_questions);

        // Neither paper picked up a row belonging to the other.
        foreach ([$first, $second] as $who) {
            $this->assertSame(4, CompetitionUserQuestion::query()
                ->where('competition_user_id', $who->id)->count());
        }
    }
}
