<?php

namespace Tests\Feature;

use App\Exceptions\ExamException;
use App\Models\CompetitionUserQuestion;
use App\Services\Competition\CompetitionExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/** The 40-second rule, enforced from the server clock with no grace window. */
class ExamTimerTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private function startedContestant(int $questions = 5): array
    {
        $competition = $this->makeCompetition(['question_count' => $questions, 'seconds_per_question' => 40]);
        $this->makeQuestions($competition, $questions);
        $participation = $this->makeContestant($competition);

        $service = app(CompetitionExamService::class);
        $service->startOrResume($participation->user, $competition);

        return [$competition, $participation->fresh(), $service];
    }

    public function test_expires_at_is_exactly_forty_seconds_after_opened_at(): void
    {
        [, $participation, $service] = $this->startedContestant();

        $service->currentQuestion($participation);

        $row = CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)
            ->where('sequence', 1)->first();

        $this->assertNotNull($row->opened_at);
        $this->assertSame(40.0, $row->opened_at->diffInSeconds($row->expires_at, false));
    }

    public function test_the_deadline_is_taken_from_the_competition_configuration(): void
    {
        $competition = $this->makeCompetition(['question_count' => 3, 'seconds_per_question' => 25]);
        $this->makeQuestions($competition, 3);
        $participation = $this->makeContestant($competition);
        $service = app(CompetitionExamService::class);

        $service->startOrResume($participation->user, $competition);
        $service->currentQuestion($participation->fresh());

        $row = CompetitionUserQuestion::query()->where('sequence', 1)->first();
        $this->assertSame(25.0, $row->opened_at->diffInSeconds($row->expires_at, false));
    }

    public function test_opened_at_is_written_once_and_a_refresh_does_not_extend_the_deadline(): void
    {
        [, $participation, $service] = $this->startedContestant();

        $service->currentQuestion($participation);
        $original = CompetitionUserQuestion::query()->where('sequence', 1)->first();

        $this->travel(15)->seconds();

        // Three refreshes; the deadline must not move.
        $service->currentQuestion($participation->fresh());
        $service->currentQuestion($participation->fresh());
        $payload = $service->currentQuestion($participation->fresh());

        $after = CompetitionUserQuestion::query()->where('sequence', 1)->first();

        $this->assertEquals($original->opened_at, $after->opened_at);
        $this->assertEquals($original->expires_at, $after->expires_at);
        $this->assertSame(1, $payload['sequence'], 'a refresh must re-serve the same question');
    }

    public function test_an_answer_inside_the_window_is_accepted(): void
    {
        [, $participation, $service] = $this->startedContestant();

        $question = $service->currentQuestion($participation);
        $this->travel(39)->seconds();

        $outcome = $service->submitAnswer($participation->fresh(), $question['question_id'], 'A');

        $this->assertTrue($outcome['accepted']);
        $this->assertNotNull(CompetitionUserQuestion::query()->where('sequence', 1)->value('answered_at'));
    }

    public function test_an_answer_after_the_deadline_is_rejected_and_the_question_times_out(): void
    {
        [, $participation, $service] = $this->startedContestant();

        $question = $service->currentQuestion($participation);
        $this->travel(41)->seconds();

        try {
            $service->submitAnswer($participation->fresh(), $question['question_id'], 'A');
            $this->fail('a late answer must not be accepted');
        } catch (ExamException $e) {
            $this->assertSame('question_expired', $e->reason);
        }

        $row = CompetitionUserQuestion::query()->where('sequence', 1)->first();
        $this->assertTrue($row->timed_out);
        $this->assertNull($row->selected_option);
        $this->assertNull($row->answered_at);
        $this->assertFalse($row->is_correct);
    }

    public function test_a_late_answer_cannot_overwrite_an_existing_timeout(): void
    {
        [, $participation, $service] = $this->startedContestant();

        $question = $service->currentQuestion($participation);
        $this->travel(41)->seconds();

        // First call sweeps the expired question to terminal.
        $service->currentQuestion($participation->fresh());

        $row = CompetitionUserQuestion::query()->where('sequence', 1)->first();
        $this->assertTrue($row->timed_out);

        // The stale tab now submits against the question it still has on screen.
        try {
            $service->submitAnswer($participation->fresh(), $question['question_id'], 'A');
            $this->fail('a timed-out question must not accept an answer');
        } catch (ExamException $e) {
            $this->assertSame('question_not_available', $e->reason);
        }

        $row->refresh();
        $this->assertTrue($row->timed_out);
        $this->assertNull($row->selected_option);
    }

    /**
     * Only the question that was actually open can expire. The clock runs per
     * question, not across the paper — there is no exam-duration column in the
     * locked schema — so a contestant who walks away loses the question they
     * left open and is served the next one with a full window.
     */
    public function test_walking_away_expires_only_the_question_that_was_open(): void
    {
        [, $participation, $service] = $this->startedContestant(3);

        $first = $service->currentQuestion($participation);
        $this->travel(10)->minutes();

        $next = $service->currentQuestion($participation->fresh());

        $expired = CompetitionUserQuestion::query()->where('sequence', 1)->first();
        $this->assertTrue($expired->timed_out);
        $this->assertNull($expired->selected_option);

        // Question 2 is served fresh, with its own full 40 seconds.
        $this->assertSame(2, $next['sequence']);
        $this->assertNotSame($first['question_id'], $next['question_id']);

        $served = CompetitionUserQuestion::query()->where('sequence', 2)->first();
        $this->assertSame(40.0, $served->opened_at->diffInSeconds($served->expires_at, false));

        // Question 3 has still never been served.
        $this->assertNull(CompetitionUserQuestion::query()->where('sequence', 3)->value('opened_at'));
        $this->assertSame('in_progress', $participation->fresh()->exam_status);
    }

    public function test_letting_every_served_question_expire_finalises_the_paper(): void
    {
        [, $participation, $service] = $this->startedContestant(2);

        // Serve and abandon each question in turn.
        for ($i = 0; $i < 2; $i++) {
            $service->currentQuestion($participation->fresh());
            $this->travel(41)->seconds();
        }

        $this->assertNull($service->currentQuestion($participation->fresh()));

        $participation->refresh();
        $this->assertSame('completed', $participation->exam_status);
        $this->assertSame(0, $participation->answered_questions);
        $this->assertSame(0, $participation->correct_answers);
        $this->assertSame(2, CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)->where('timed_out', true)->count());
    }

    public function test_the_payload_never_carries_the_answer_key(): void
    {
        [, $participation, $service] = $this->startedContestant();

        $payload = $service->currentQuestion($participation);

        $this->assertArrayNotHasKey('correct_option', $payload);
        $this->assertArrayNotHasKey('is_correct', $payload);
        $this->assertArrayHasKey('expires_at', $payload);
        $this->assertArrayHasKey('server_time', $payload);
        $this->assertSame(['A', 'B', 'C', 'D'], array_keys($payload['options']));
    }
}
