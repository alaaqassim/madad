<?php

namespace Tests\Feature;

use App\Models\CompetitionUser;
use App\Services\Competition\CompetitionExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * The timeline: time never pauses, and no question ever gets more than
 * seconds_per_question.
 *
 * Two rules produce every case below.
 *
 *   position   effective index = max(current_question, floor(elapsed / s))
 *   window     deadline = min(started_at + (i+1)*s, arrived_at + s)
 *
 * The second is what makes "advance immediately on submit" safe: a contestant
 * who answers in five seconds moves on at once, but inherits a fresh full
 * window rather than the remainder of a longer slot.
 */
class ExamTimelineTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private function exam(): CompetitionExamService
    {
        return app(CompetitionExamService::class);
    }

    public function test_the_first_question_opens_with_a_full_window(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $payload = $this->exam()->currentQuestion($contestant->refresh(), $competition);

        $this->assertSame(1, $payload['sequence']);
        $this->assertEqualsWithDelta(40.0, $payload['seconds_remaining'], 1.0);
        $this->assertSame(
            Carbon::parse($payload['opened_at'])->addSeconds(40)->toIso8601String(),
            $payload['expires_at'],
        );
    }

    public function test_a_refresh_does_not_extend_the_window(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $first = $this->exam()->currentQuestion($contestant->refresh(), $competition);

        $this->travel(12)->seconds();

        $second = $this->exam()->currentQuestion($contestant->refresh(), $competition);

        $this->assertSame($first['question_id'], $second['question_id']);
        $this->assertSame($first['expires_at'], $second['expires_at'], 'the deadline moved');
        $this->assertEqualsWithDelta(28.0, $second['seconds_remaining'], 1.0);
    }

    public function test_answering_early_advances_at_once_but_never_grants_more_than_one_window(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $contestant->refresh();

        // Five seconds in — far ahead of the fixed grid, whose slot 1 does not
        // open until t+40 and does not close until t+80.
        $this->travel(5)->seconds();
        $this->exam()->submitAnswer($contestant, $competition, $contestant->questionIdAt(0), 'A');

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $competition);

        $this->assertSame(2, $payload['sequence'], 'the contestant did not advance immediately');
        $this->assertEqualsWithDelta(
            40.0,
            $payload['seconds_remaining'],
            1.0,
            'a fast contestant inherited the remainder of a longer slot',
        );
        $this->assertLessThanOrEqual(40.0, $payload['seconds_remaining']);
    }

    public function test_seconds_remaining_is_never_above_seconds_per_question(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $contestant->refresh();

        // Race through the paper as fast as the engine will allow.
        for ($position = 0; $position < 4; $position++) {
            $payload = $this->exam()->currentQuestion($contestant->refresh(), $competition);

            $this->assertLessThanOrEqual(
                40.0,
                $payload['seconds_remaining'],
                "position {$position} was given more than one window",
            );

            $this->travel(1)->seconds();
            $this->exam()->submitAnswer($contestant->refresh(), $competition, $payload['question_id'], 'A');
        }
    }

    /**
     * The reconnect the business asked about, to the second.
     *
     *   start 08:00:00, return 08:15:00 → elapsed 900s, 900/40 = 22.5 → index 22
     */
    public function test_returning_after_fifteen_minutes_resumes_at_the_real_position(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 08:00:00'));

        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->makeQuestions($competition, 75);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $this->exam()->currentQuestion($contestant->refresh(), $competition);

        $this->assertSame(0, $contestant->refresh()->current_question);

        // The contestant disconnects. Fifteen minutes of real time pass.
        Carbon::setTestNow(Carbon::parse('2026-09-05 08:15:00'));

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $competition);

        $this->assertSame(22, $contestant->refresh()->current_question, 'the elapsed timeline was not applied');
        $this->assertSame(23, $payload['sequence']);

        // Slot 22 runs 08:14:40 → 08:15:20, so twenty seconds of it survive.
        $this->assertEqualsWithDelta(20.0, $payload['seconds_remaining'], 0.5);

        // The twenty-two positions the clock passed are spent, permanently.
        $this->assertSame(str_repeat(CompetitionUser::NO_ANSWER, 22), substr($contestant->answers, 0, 22));
        $this->assertSame(0, $contestant->answered_questions);

        Carbon::setTestNow();
    }

    public function test_a_contestant_who_leaves_at_position_five_does_not_get_a_fresh_window_there(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->makeQuestions($competition, 75);
        $contestant = $this->makeContestant($competition);

        // Persisted at position 5 on a timeline that began ten minutes ago.
        $startedAt = now()->subSeconds(600);
        $this->placeAt($contestant, $competition, 5, $startedAt, $startedAt->copy()->addSeconds(200));

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $competition);

        $this->assertSame(15, $contestant->refresh()->current_question, '600s / 40s = 15');
        $this->assertSame(16, $payload['sequence']);
        $this->assertNotSame(6, $payload['sequence'], 'the contestant resumed where they left off');
        $this->assertLessThanOrEqual(40.0, $payload['seconds_remaining']);
    }

    public function test_the_position_never_moves_backwards(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->makeQuestions($competition, 75);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $contestant->refresh();

        // Answer three questions quickly: position 3, timeline still at 0.
        for ($i = 0; $i < 3; $i++) {
            $this->travel(2)->seconds();
            $this->exam()->submitAnswer($contestant->refresh(), $competition, null, 'A');
        }

        $this->assertSame(3, $contestant->refresh()->current_question);

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $competition);

        $this->assertSame(4, $payload['sequence'], 'the fixed timeline dragged the contestant backwards');
        $this->assertSame(3, $contestant->refresh()->current_question);
    }

    public function test_the_exam_completes_once_the_full_duration_has_elapsed(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->makeQuestions($competition, 75);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $this->exam()->currentQuestion($contestant->refresh(), $competition);

        // 75 x 40 = 3000 seconds. One second past it, the paper is over.
        $this->travel(3001)->seconds();

        $this->assertNull($this->exam()->currentQuestion($contestant->refresh(), $competition));

        $contestant->refresh();

        $this->assertSame(CompetitionUser::EXAM_COMPLETED, $contestant->exam_status);
        $this->assertNotNull($contestant->completed_at);
        $this->assertSame(0, $contestant->answered_questions);
        $this->assertSame(str_repeat(CompetitionUser::NO_ANSWER, 75), $contestant->answers);
    }

    public function test_the_exam_is_still_live_one_second_before_the_full_duration(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->makeQuestions($competition, 75);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $this->travel(2999)->seconds();

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $competition);

        $this->assertNotNull($payload);
        $this->assertSame(75, $payload['sequence']);
        $this->assertSame(CompetitionUser::EXAM_IN_PROGRESS, $contestant->refresh()->exam_status);
    }

    public function test_the_payload_carries_server_time_and_no_device_clock_is_read(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);

        // A client insisting it is next year changes nothing: the request body
        // is not consulted for timing at any point.
        $response = $this->actingAs($contestant->user)
            ->getJson('/api/exam/current', ['X-Client-Time' => now()->addYear()->toIso8601String()])
            ->assertOk();

        $payload = $response->json('question');

        $this->assertEqualsWithDelta(
            0,
            Carbon::parse($payload['server_time'])->diffInSeconds(now()),
            2,
        );
        $this->assertEqualsWithDelta(40.0, $payload['seconds_remaining'], 1.0);
    }

    public function test_the_window_is_taken_from_the_competition_configuration(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5, 'seconds_per_question' => 15]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $payload = $this->exam()->currentQuestion($contestant->refresh(), $competition);

        $this->assertEqualsWithDelta(15.0, $payload['seconds_remaining'], 1.0);
        $this->assertSame(
            Carbon::parse($payload['opened_at'])->addSeconds(15)->toIso8601String(),
            $payload['expires_at'],
        );
    }

    public function test_the_payload_never_carries_the_answer_key(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $payload = $this->exam()->currentQuestion($contestant->refresh(), $competition);

        $this->assertSame([
            'question_id', 'question_text', 'options', 'sequence', 'total_questions',
            'opened_at', 'expires_at', 'server_time', 'seconds_remaining',
        ], array_keys($payload));

        $this->assertArrayNotHasKey('correct_option', $payload);
        $this->assertArrayNotHasKey('is_correct', $payload);
    }
}
