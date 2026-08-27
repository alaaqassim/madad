<?php

namespace Tests\Feature;

use App\Exceptions\ExamException;
use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
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
 * actually be holding — A MODEL INSTANCE READ BEFORE THE OTHER REQUEST WROTE —
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

    /** @return array{0: CompetitionSettings, 1: CompetitionUser} */
    private function contestant(int $questions = 5): array
    {
        $competition = $this->makeCompetition(['question_count' => $questions, 'seconds_per_question' => 40]);
        $this->makeQuestions($competition, $questions);

        return [$competition, $this->makeContestant($competition)];
    }

    // ── 1. two simultaneous start requests ──────────────────────────────────

    public function test_two_simultaneous_starts_persist_exactly_one_order(): void
    {
        [$competition, $participation] = $this->contestant(5);
        $service = $this->service();

        // Two requests, each holding the row as it was before either wrote.
        $first = $participation->fresh();
        $second = $participation->fresh();

        $service->startOrResume($first->user, $competition);
        $service->startOrResume($second->user, $competition);

        $participation->refresh();

        $this->assertCount(5, $participation->order());
        $this->assertSame($participation->order(), array_values(array_unique($participation->order())));
        $this->assertSame(0, $participation->current_question);
        $this->assertSame(CompetitionUser::EXAM_IN_PROGRESS, $participation->exam_status);
    }

    public function test_a_second_start_does_not_reshuffle_or_restart(): void
    {
        [$competition, $participation] = $this->contestant(5);
        $service = $this->service();

        $service->startOrResume($participation->user, $competition);
        $participation->refresh();

        $order = $participation->order();
        $startedAt = $participation->started_at;

        $this->travel(3)->seconds();
        $service->startOrResume($participation->user, $competition);

        $participation->refresh();

        $this->assertSame($order, $participation->order(), 'the paper was reshuffled');
        $this->assertEquals($startedAt, $participation->started_at, 'the clock was restarted');
    }

    // ── 2. two submissions for the same position ────────────────────────────

    public function test_two_submissions_for_the_same_position_resolve_to_one_answer(): void
    {
        [$competition, $participation] = $this->contestant(5);
        $service = $this->service();

        $service->startOrResume($participation->user, $competition);
        $participation->refresh();

        $questionId = $participation->questionIdAt(0);

        // Both requests believe they are answering position 0.
        $stale = $participation->fresh();

        $service->submitAnswer($participation, $competition, $questionId, 'A');

        try {
            $service->submitAnswer($stale, $competition, $questionId, 'B');
            $this->fail('the second submission was accepted');
        } catch (ExamException $e) {
            $this->assertSame('question_not_available', $e->reason);
        }

        $participation->refresh();

        $this->assertSame('A', $participation->answerAt(0), 'the loser of the race overwrote the winner');
        $this->assertSame(1, $participation->current_question, 'the index advanced twice');
        $this->assertSame(1, $participation->answered_questions);
    }

    public function test_an_answer_arriving_after_the_window_loses_to_the_clock(): void
    {
        [$competition, $participation] = $this->contestant(5);
        $service = $this->service();

        $service->startOrResume($participation->user, $competition);
        $participation->refresh();

        $inFlight = $participation->fresh();
        $questionId = $participation->questionIdAt(0);

        // The request was composed inside the window and lands outside it.
        $this->travel(41)->seconds();

        try {
            $service->submitAnswer($inFlight, $competition, $questionId, 'A');
            $this->fail('a late answer was accepted');
        } catch (ExamException $e) {
            $this->assertSame('question_expired', $e->reason);
        }

        $participation->refresh();

        $this->assertNull($participation->answerAt(0));
        $this->assertSame(0, $participation->answered_questions);
        $this->assertGreaterThanOrEqual(1, $participation->current_question);
    }

    public function test_an_answer_on_the_last_second_is_accepted_and_cannot_then_expire(): void
    {
        [$competition, $participation] = $this->contestant(5);
        $service = $this->service();

        $service->startOrResume($participation->user, $competition);
        $participation->refresh();

        $this->travel(39)->seconds();

        $service->submitAnswer($participation, $competition, $participation->questionIdAt(0), 'A');

        $participation->refresh();
        $this->assertSame('A', $participation->answerAt(0));

        // The clock rolls past the old deadline: the recorded answer stands.
        $this->travel(5)->seconds();
        $service->currentQuestion($participation->refresh(), $competition);

        $this->assertSame('A', $participation->refresh()->answerAt(0));
        $this->assertSame(1, $participation->answered_questions);
    }

    // ── 3. simultaneous reads ───────────────────────────────────────────────

    /**
     * Reading cannot move a deadline, because there is no deadline to move.
     * The slot boundaries are arithmetic on started_at, so two readers agree by
     * construction rather than by one of them winning a write.
     */
    public function test_simultaneous_refreshes_report_the_same_fixed_slot(): void
    {
        [$competition, $participation] = $this->contestant(5);
        $service = $this->service();

        $service->startOrResume($participation->user, $competition);
        $participation->refresh();

        $startedAt = $participation->started_at->copy();

        $this->travel(5)->seconds();

        $first = $service->currentQuestion($participation->fresh(), $competition);
        $second = $service->currentQuestion($participation->fresh(), $competition);

        $this->assertSame($first['expires_at'], $second['expires_at']);
        $this->assertSame($first['opened_at'], $second['opened_at']);
        $this->assertSame($startedAt->toIso8601String(), $first['opened_at'], 'the window was reopened at the read');
        $this->assertEquals($startedAt, $participation->refresh()->started_at, 'the anchor moved');
    }

    // ── 4. the portal closing underneath a request ──────────────────────────

    public function test_closing_mid_flight_stops_the_contestant_without_corrupting_state(): void
    {
        [$competition, $participation] = $this->contestant(5);
        $service = $this->service();

        $service->startOrResume($participation->user, $competition);
        $participation->refresh();

        $service->submitAnswer($participation, $competition, null, 'A');

        $before = json_encode($participation->fresh()->only([
            'question_order', 'current_question', 'answers', 'correct_answers',
            'answered_questions', 'exam_status',
        ]));

        $competition->forceFill(['status' => CompetitionSettings::STATUS_CLOSED])->save();

        foreach ([
            fn () => $service->currentQuestion($participation->fresh(), $competition),
            fn () => $service->submitAnswer($participation->fresh(), $competition, null, 'B'),
            fn () => $service->startOrResume($participation->user, $competition),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a closed competition allowed the request through');
            } catch (ExamException $e) {
                $this->assertSame('competition_closed', $e->reason);
            }
        }

        $after = json_encode($participation->fresh()->only([
            'question_order', 'current_question', 'answers', 'correct_answers',
            'answered_questions', 'exam_status',
        ]));

        $this->assertSame($before, $after, 'a refused request changed stored exam state');
    }

    // ── 5. finalisation ─────────────────────────────────────────────────────

    public function test_repeated_finalisation_does_not_move_the_score_or_the_finish_line(): void
    {
        [$competition, $participation] = $this->contestant(3);
        $service = $this->service();

        $service->startOrResume($participation->user, $competition);

        for ($position = 0; $position < 3; $position++) {
            $service->submitAnswer($participation->refresh(), $competition, null, 'A');
        }

        $participation->refresh();
        $completedAt = $participation->completed_at;
        $correct = $participation->correct_answers;

        $this->travel(120)->seconds();

        $service->currentQuestion($participation->fresh(), $competition);
        $service->startOrResume($participation->user, $competition);
        $service->currentQuestion($participation->fresh(), $competition);

        $participation->refresh();

        $this->assertEquals($completedAt, $participation->completed_at);
        $this->assertSame($correct, $participation->correct_answers);
        $this->assertSame(3, $participation->current_question);
    }

    public function test_a_stale_request_cannot_answer_a_paper_that_has_already_finished(): void
    {
        [$competition, $participation] = $this->contestant(3);
        $service = $this->service();

        $service->startOrResume($participation->user, $competition);
        $participation->refresh();

        // A request composed while the paper was still live.
        $stale = $participation->fresh();

        for ($position = 0; $position < 3; $position++) {
            $service->submitAnswer($participation->refresh(), $competition, null, 'A');
        }

        try {
            $service->submitAnswer($stale, $competition, null, 'B');
            $this->fail('a finished paper accepted another answer');
        } catch (ExamException $e) {
            $this->assertSame('exam_completed', $e->reason);
        }

        $this->assertSame(3, $participation->refresh()->answered_questions);
    }

    // ── 6. contestants racing each other ────────────────────────────────────

    public function test_two_contestants_racing_keep_separate_papers_and_scores(): void
    {
        [$competition, $first] = $this->contestant(5);
        $second = $this->makeContestant($competition);
        $service = $this->service();

        $service->startOrResume($first->user, $competition);
        $service->startOrResume($second->user, $competition);

        $first->refresh();
        $second->refresh();

        // Interleave their answers, as two simultaneous contestants would. Each
        // answer opens that contestant's next question at once, independently.
        for ($position = 0; $position < 4; $position++) {
            $service->submitAnswer($first->refresh(), $competition, null, $this->correctOptionAt($first->refresh(), $position));
            $service->submitAnswer($second->refresh(), $competition, null, $this->wrongOptionAt($second->refresh(), $position));
        }

        $first->refresh();
        $second->refresh();

        $this->assertSame(4, $first->correct_answers);
        $this->assertSame(0, $second->correct_answers);
        $this->assertSame(4, $first->current_question);
        $this->assertSame(4, $second->current_question);
        $this->assertNotSame($first->answers, $second->answers);
    }
}
