<?php

namespace Tests\Feature;

use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * CLOSED MEANS THE COMPETITION HAS ENDED.
 *
 * This is the confirmed business rule, and it is stronger than "block new
 * starts". Once the status is `closed`:
 *
 *   • no new contestant may start;
 *   • an in-progress contestant may NOT resume;
 *   • an in-progress contestant may NOT fetch another question;
 *   • an in-progress contestant may NOT submit another answer.
 *
 * Each of those four is asserted separately below, over HTTP, because a partial
 * implementation of this rule would pass a single combined test while leaving a
 * contestant able to keep playing after the competition ended.
 */
class CompetitionClosureTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    /** A contestant mid-exam, with a live question on screen. */
    private function midExam(int $questions = 5): array
    {
        $competition = $this->makeCompetition(['question_count' => $questions]);
        $this->makeQuestions($competition, $questions);
        $participation = $this->makeContestant($competition);

        $this->actingAs($participation->user);
        $question = $this->postJson('/api/exam/start')->assertOk()->json('question');

        return [$competition, $participation, $question];
    }

    public function test_a_closed_competition_blocks_a_new_start(): void
    {
        $competition = $this->makeCompetition(['question_count' => 3]);
        $this->makeQuestions($competition, 3);
        $participation = $this->makeContestant($competition);

        $competition->forceFill(['status' => CompetitionSettings::STATUS_CLOSED])->save();

        $this->actingAs($participation->user)->postJson('/api/exam/start')
            ->assertStatus(403)
            ->assertJsonPath('reason', 'competition_closed');

        $this->assertSame(CompetitionUser::EXAM_NOT_STARTED, $participation->fresh()->exam_status);
        $this->assertNull($participation->fresh()->question_order, 'a refused start dealt a paper');
    }

    public function test_a_closed_competition_blocks_an_in_progress_contestant_from_resuming(): void
    {
        [$competition, $participation] = $this->midExam();

        $competition->forceFill(['status' => CompetitionSettings::STATUS_CLOSED])->save();

        $this->actingAs($participation->user)->postJson('/api/exam/start')
            ->assertStatus(403)
            ->assertJsonPath('reason', 'competition_closed');

        // Still in progress, and untouched — closing ends the competition, it
        // does not finalise anybody's paper.
        $this->assertSame(CompetitionUser::EXAM_IN_PROGRESS, $participation->fresh()->exam_status);
    }

    public function test_a_closed_competition_blocks_fetching_another_question(): void
    {
        [$competition, $participation] = $this->midExam();

        $competition->forceFill(['status' => CompetitionSettings::STATUS_CLOSED])->save();

        $this->actingAs($participation->user)->getJson('/api/exam/current')
            ->assertStatus(403)
            ->assertJsonPath('reason', 'competition_closed');
    }

    public function test_a_closed_competition_blocks_submitting_another_answer(): void
    {
        [$competition, $participation, $question] = $this->midExam();

        $competition->forceFill(['status' => CompetitionSettings::STATUS_CLOSED])->save();

        $this->actingAs($participation->user)->postJson('/api/exam/answer', [
            'question_id' => $question['question_id'],
            'selected_option' => 'A',
        ])->assertStatus(403)->assertJsonPath('reason', 'competition_closed');

        // The answer must not have landed.
        $participation->refresh();

        $this->assertNull($participation->answerAt(0));
        $this->assertSame(0, $participation->current_question);
        $this->assertSame(0, $participation->answered_questions);
    }

    public function test_closing_mid_question_does_not_alter_the_paper_or_the_score(): void
    {
        [$competition, $participation, $question] = $this->midExam(4);

        // Answer one question legitimately first.
        $this->postJson('/api/exam/answer', [
            'question_id' => $question['question_id'],
            'selected_option' => 'A',
        ])->assertOk();

        $before = json_encode($participation->fresh()->only([
            'question_order', 'current_question', 'current_question_started_at',
            'answers', 'correct_answers', 'answered_questions', 'exam_status',
        ]));

        $competition->forceFill(['status' => CompetitionSettings::STATUS_CLOSED])->save();

        $this->getJson('/api/exam/current')->assertStatus(403);
        $this->postJson('/api/exam/answer', ['question_id' => $question['question_id'], 'selected_option' => 'B'])
            ->assertStatus(403);

        $after = json_encode($participation->fresh()->only([
            'question_order', 'current_question', 'current_question_started_at',
            'answers', 'correct_answers', 'answered_questions', 'exam_status',
        ]));

        $this->assertSame($before, $after, 'a refused request must not change stored exam state');
    }

    public function test_the_public_status_endpoint_reports_the_closure_distinctly(): void
    {
        $competition = $this->makeCompetition(['status' => CompetitionSettings::STATUS_CLOSED]);

        $this->getJson('/api/competition/status')
            ->assertOk()
            ->assertJsonPath('open', false)
            ->assertJsonPath('status', 'closed')
            // Distinct from a draft/ready portal: closed will never open again,
            // so the client must not offer "try later".
            ->assertJsonPath('reason', 'competition_closed');

        $competition->forceFill(['status' => CompetitionSettings::STATUS_READY])->save();

        $this->getJson('/api/competition/status')
            ->assertOk()
            ->assertJsonPath('open', false)
            ->assertJsonPath('reason', 'competition_not_open');
    }

    public function test_a_completed_contestant_can_still_read_their_result_after_closure(): void
    {
        $competition = $this->makeCompetition(['question_count' => 1, 'show_result' => true]);
        $this->makeQuestions($competition, 1);
        $participation = $this->makeContestant($competition);

        $this->actingAs($participation->user);
        $question = $this->postJson('/api/exam/start')->assertOk()->json('question');
        $this->postJson('/api/exam/answer', [
            'question_id' => $question['question_id'],
            'selected_option' => 'A',
        ])->assertOk();

        $competition->forceFill(['status' => CompetitionSettings::STATUS_CLOSED])->save();

        // Reading a finished result is not participation, and the gate is not
        // consulted for it — a contestant whose exam ended before the portal
        // closed keeps access to their own outcome.
        $this->getJson('/api/exam/result')
            ->assertOk()
            ->assertJsonPath('exam_status', CompetitionUser::EXAM_COMPLETED);
    }
}
