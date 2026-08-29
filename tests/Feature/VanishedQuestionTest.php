<?php

namespace Tests\Feature;

use App\Models\CompetitionQuestion;
use App\Models\CompetitionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * A question deleted out from under a paper that already names it.
 *
 * Papers hold question ids, and ids can stop resolving: somebody deletes a
 * question they judged wrong, or reloads the bank mid-competition. Preflight
 * calls that a blocker and the operational rule is not to do it - but a rule is
 * not a mechanism, and what the engine did without one was the worst answer
 * available.
 *
 * The contestant could not read the question and could not answer it, so they
 * sat on an error screen until the window timed out. Worse, the answer BEFORE
 * it came back as a failure although it had been recorded, because the response
 * dies while preparing the next question - and a contestant told their answer
 * failed submits it again.
 *
 * Neither is acceptable for a data problem the contestant did not cause. So the
 * position is skipped, the clock is not charged for it, and a recorded answer
 * is always reported as recorded.
 */
class VanishedQuestionTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    /** A contestant mid-paper, with the question at $position deleted. */
    private function paperMissing(int $position, int $questions = 5): array
    {
        $competition = $this->makeCompetition(['question_count' => $questions]);
        $this->makeQuestions($competition, $questions);
        $participation = $this->makeContestant($competition);

        $this->actingAs($participation->user);
        $this->postJson('/api/exam/start')->assertOk();

        $participation->refresh();
        $doomed = $participation->questionIdAt($position);

        CompetitionQuestion::query()->whereKey($doomed)->delete();

        return [$participation, $doomed];
    }

    public function test_an_answer_is_reported_as_recorded_even_when_the_next_question_is_gone(): void
    {
        $this->freezeTime();

        // Position 1 is deleted, so answering position 0 succeeds and then the
        // response tries to prepare a question that no longer exists.
        [$participation] = $this->paperMissing(1);

        $response = $this->postJson('/api/exam/answer', [
            'question_id' => $participation->refresh()->questionIdAt(0),
            'selected_option' => 'A',
        ]);

        $response->assertOk()->assertJsonPath('accepted', true);

        $participation->refresh();

        $this->assertSame('A', $participation->answerAt(0), 'the answer was not recorded');
        $this->assertSame(1, $participation->answered_questions);
    }

    public function test_a_contestant_standing_on_a_deleted_question_is_carried_past_it(): void
    {
        $this->freezeTime();

        [$participation, $doomed] = $this->paperMissing(2);

        // Walk to the position before it, then onto it.
        foreach ([0, 1] as $position) {
            $this->postJson('/api/exam/answer', [
                'question_id' => $participation->refresh()->questionIdAt($position),
                'selected_option' => 'A',
            ])->assertOk();
        }

        $question = $this->getJson('/api/exam/current')->assertOk()->json('question');

        $participation->refresh();

        $this->assertSame(3, (int) $participation->current_question, 'the contestant was left standing on it');
        $this->assertNotSame($doomed, $question['question_id']);
        $this->assertSame($participation->questionIdAt(3), $question['question_id']);
    }

    public function test_the_skipped_position_counts_as_unanswered(): void
    {
        $this->freezeTime();

        [$participation] = $this->paperMissing(2);

        foreach ([0, 1] as $position) {
            $this->postJson('/api/exam/answer', [
                'question_id' => $participation->refresh()->questionIdAt($position),
                'selected_option' => 'A',
            ])->assertOk();
        }

        $this->getJson('/api/exam/current')->assertOk();

        $participation->refresh();

        $this->assertNull($participation->answerAt(2), 'a question nobody could answer was marked answered');
        $this->assertSame(2, $participation->answered_questions);
    }

    public function test_the_contestant_is_not_charged_time_for_our_missing_question(): void
    {
        $this->freezeTime();

        [$participation] = $this->paperMissing(1);

        // Ten seconds into the first question, they answer. The second question
        // is gone; the third must open with a FULL window, not with the thirty
        // seconds that were left of a window nobody could use.
        $this->travel(10)->seconds();

        $this->postJson('/api/exam/answer', [
            'question_id' => $participation->refresh()->questionIdAt(0),
            'selected_option' => 'A',
        ])->assertOk();

        $question = $this->getJson('/api/exam/current')->assertOk()->json('question');

        $this->assertSame(3, $question['sequence'], 'the skipped position was not stepped over');
        $this->assertSame(40.0, round($question['seconds_remaining']), 'the contestant paid for our data problem');
    }

    public function test_submitting_the_deleted_question_is_refused_in_words_not_a_404(): void
    {
        $this->freezeTime();

        [$participation, $doomed] = $this->paperMissing(1);

        $this->postJson('/api/exam/answer', [
            'question_id' => $participation->refresh()->questionIdAt(0),
            'selected_option' => 'A',
        ])->assertOk();

        $response = $this->postJson('/api/exam/answer', [
            'question_id' => $doomed,
            'selected_option' => 'B',
        ]);

        /*
         * `question_expired`, and no new vocabulary for this case.
         *
         * The skip left that position passed and unanswered, which is exactly
         * what question_expired already means - so the contestant gets the same
         * answer as anyone whose window closed on them, and no signal that a
         * question was removed. A code of its own would say more about our data
         * than a contestant is owed, and would need handling everywhere the
         * client branches on a reason.
         */
        $response->assertStatus(422)->assertJsonPath('reason', 'question_expired');
    }

    public function test_a_deleted_last_question_completes_the_exam_rather_than_hanging_it(): void
    {
        $this->freezeTime();

        [$participation] = $this->paperMissing(4);

        foreach ([0, 1, 2, 3] as $position) {
            $this->postJson('/api/exam/answer', [
                'question_id' => $participation->refresh()->questionIdAt($position),
                'selected_option' => 'A',
            ])->assertOk();
        }

        $this->getJson('/api/exam/current')->assertOk()->assertJsonPath('question', null);

        $participation->refresh();

        $this->assertTrue($participation->isCompleted());
        $this->assertNotNull($participation->completed_at);
        $this->assertSame(4, $participation->answered_questions);
    }

    public function test_an_emptied_bank_ends_the_exam_instead_of_spinning(): void
    {
        $this->freezeTime();

        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $participation = $this->makeContestant($competition);

        $this->actingAs($participation->user);
        $this->postJson('/api/exam/start')->assertOk();

        // Every question, gone. The skip must terminate.
        CompetitionQuestion::query()->delete();

        $this->getJson('/api/exam/current')->assertOk()->assertJsonPath('question', null);

        $this->assertTrue($participation->refresh()->isCompleted());
        $this->assertSame(CompetitionUser::EXAM_COMPLETED, $participation->exam_status);
    }
}
