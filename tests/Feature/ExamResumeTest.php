<?php

namespace Tests\Feature;

use App\Models\CompetitionUser;
use App\Services\Competition\CompetitionExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * Progress lives in the database, not in a session.
 *
 * The exam must survive a closed browser, a logout, a lost session store and a
 * different computer. Nothing in the engine reads the session to decide where a
 * contestant is; the session may mirror the index, but the row is the record.
 */
class ExamResumeTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private function exam(): CompetitionExamService
    {
        return app(CompetitionExamService::class);
    }

    public function test_progress_is_persisted_to_the_database_on_every_answer(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $contestant->refresh();

        $option = $this->correctOptionAt($contestant, 0);
        $this->exam()->submitAnswer($contestant, $competition, $contestant->questionIdAt(0), $option);

        $this->assertDatabaseHas('competition_users', [
            'id' => $contestant->id,
            'current_question' => 1,
            'exam_status' => CompetitionUser::EXAM_IN_PROGRESS,
        ]);

        $this->assertSame($option, $contestant->refresh()->answerAt(0));
    }

    public function test_a_brand_new_session_resumes_from_the_database(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->makeQuestions($competition, 75);
        $contestant = $this->makeContestant($competition);

        $this->actingAs($contestant->user)->postJson('/api/exam/start')->assertOk();
        $this->actingAs($contestant->user)->postJson('/api/exam/answer', [
            'question_id' => $contestant->refresh()->questionIdAt(0),
            'selected_option' => 'A',
        ])->assertOk();

        $this->assertSame(1, $contestant->refresh()->current_question);

        // Everything the browser held is gone: cookie, session, tab.
        $this->post('/api/logout');
        $this->flushSession();

        $resumed = $this->actingAs($contestant->user)
            ->postJson('/api/exam/start')
            ->assertOk()
            ->json('question');

        $this->assertSame(2, $resumed['sequence'], 'the exam did not resume from the row');
        $this->assertSame($contestant->refresh()->questionIdAt(1), $resumed['question_id']);
    }

    public function test_a_second_device_sees_the_same_position(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->makeQuestions($competition, 75);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $contestant->refresh();

        $this->exam()->submitAnswer($contestant, $competition, null, 'B');
        $this->exam()->submitAnswer($contestant->refresh(), $competition, null, 'C');

        // A different machine, its own session, the same account.
        $this->flushSession();

        $onOtherDevice = $this->actingAs($contestant->user)
            ->getJson('/api/exam/current')
            ->assertOk()
            ->json('question');

        $this->assertSame(3, $onOtherDevice['sequence']);
        $this->assertSame($contestant->refresh()->questionIdAt(2), $onOtherDevice['question_id']);
    }

    public function test_the_session_is_not_consulted_for_progress(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->makeQuestions($competition, 75);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $contestant->refresh();
        $this->exam()->submitAnswer($contestant, $competition, null, 'A');

        // A session claiming a different position must not be believed.
        session(['current_question' => 40]);

        $payload = $this->actingAs($contestant->user)
            ->getJson('/api/exam/current')
            ->assertOk()
            ->json('question');

        $this->assertSame(2, $payload['sequence'], 'the session overrode the database');
    }

    public function test_the_state_machine_reports_the_right_stage_on_login(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);

        $fresh = $this->makeContestant($competition);
        $this->actingAs($fresh->user)
            ->getJson('/api/competition/status')
            ->assertJsonPath('participation.exam_status', CompetitionUser::EXAM_NOT_STARTED);

        $started = $this->makeContestant($competition);
        $this->exam()->startOrResume($started->user, $competition);
        $this->actingAs($started->user)
            ->getJson('/api/competition/status')
            ->assertJsonPath('participation.exam_status', CompetitionUser::EXAM_IN_PROGRESS);

        $done = $this->makeContestant($competition);
        $this->exam()->startOrResume($done->user, $competition);

        for ($i = 0; $i < 5; $i++) {
            $this->exam()->submitAnswer($done->refresh(), $competition, null, 'A');
        }

        $this->actingAs($done->user)
            ->getJson('/api/competition/status')
            ->assertJsonPath('participation.exam_status', CompetitionUser::EXAM_COMPLETED);
    }

    public function test_current_before_starting_reports_not_started_rather_than_completed(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->actingAs($contestant->user)
            ->getJson('/api/exam/current')
            ->assertOk()
            ->assertJsonPath('exam_status', CompetitionUser::EXAM_NOT_STARTED)
            ->assertJsonPath('question', null);

        // Reading the current question must not silently start or end the exam.
        $this->assertSame(CompetitionUser::EXAM_NOT_STARTED, $contestant->refresh()->exam_status);
        $this->assertNull($contestant->started_at);
    }

    public function test_resuming_a_completed_exam_returns_the_completion_state(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);

        for ($i = 0; $i < 5; $i++) {
            $this->exam()->submitAnswer($contestant->refresh(), $competition, null, 'A');
        }

        $completedAt = $contestant->refresh()->completed_at;

        $this->actingAs($contestant->user)
            ->postJson('/api/exam/start')
            ->assertOk()
            ->assertJsonPath('exam_status', CompetitionUser::EXAM_COMPLETED)
            ->assertJsonPath('question', null);

        // Re-entering must not restart, reshuffle, or move completed_at.
        $this->assertEquals($completedAt, $contestant->refresh()->completed_at);
        $this->assertSame(5, $contestant->current_question);
    }
}
