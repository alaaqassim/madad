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
 * Submitting an answer.
 *
 * The client sends an option. It does not send a position, and the question id
 * it may send is only ever checked against the one the server already resolved
 * from question_order[current_question] — never used to choose.
 *
 * Note `enterSlot()` before each successive answer. Under the fixed timeline
 * position N is only answerable inside [started_at + N·s, started_at + (N+1)·s),
 * so a contestant who answers instantly cannot fire the next answer instantly
 * too — the clock has to reach the next slot first. That is the rule under
 * test as much as it is scaffolding.
 */
class AnswerSubmissionTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    /** @return array{0: CompetitionSettings, 1: CompetitionUser, 2: CompetitionExamService} */
    private function startedContestant(int $questions = 5): array
    {
        $competition = $this->makeCompetition(['question_count' => $questions]);
        $this->makeQuestions($competition, $questions);
        $participation = $this->makeContestant($competition);
        $service = app(CompetitionExamService::class);
        $service->startOrResume($participation->user, $competition);

        return [$competition, $participation->fresh(), $service];
    }

    public function test_a_correct_answer_is_graded_correct(): void
    {
        [$competition, $contestant, $service] = $this->startedContestant();

        $service->submitAnswer($contestant, $competition, null, $this->correctOptionAt($contestant, 0));

        $this->assertSame(1, $contestant->refresh()->correct_answers);
        $this->assertSame(1, $contestant->answered_questions);
    }

    public function test_a_wrong_answer_is_graded_incorrect(): void
    {
        [$competition, $contestant, $service] = $this->startedContestant();

        $service->submitAnswer($contestant, $competition, null, $this->wrongOptionAt($contestant, 0));

        $this->assertSame(0, $contestant->refresh()->correct_answers);
        $this->assertSame(1, $contestant->answered_questions);
    }

    public function test_grading_uses_the_question_at_the_current_index(): void
    {
        [$competition, $contestant, $service] = $this->startedContestant();

        // Each answer is graded against the question at the index it was given
        // at, never the one the option happens to suit.
        $service->submitAnswer($contestant, $competition, null, $this->correctOptionAt($contestant, 0));

        $this->enterSlot($contestant, $competition, 1);
        $service->submitAnswer($contestant->refresh(), $competition, null, $this->correctOptionAt($contestant, 1));

        $this->assertSame(2, $contestant->refresh()->correct_answers);
        $this->assertSame($this->correctOptionAt($contestant, 0), $contestant->answerAt(0));
        $this->assertSame($this->correctOptionAt($contestant, 1), $contestant->answerAt(1));
    }

    public function test_the_response_does_not_tell_the_contestant_whether_they_were_right(): void
    {
        [$competition, $contestant, $service] = $this->startedContestant();

        $outcome = $service->submitAnswer($contestant, $competition, null, $this->correctOptionAt($contestant, 0));

        $this->assertSame(['accepted', 'sequence', 'exam_status'], array_keys($outcome));
        $this->assertArrayNotHasKey('is_correct', $outcome);
        $this->assertArrayNotHasKey('correct_option', $outcome);
    }

    public function test_answering_advances_the_index(): void
    {
        [$competition, $contestant, $service] = $this->startedContestant();

        $this->assertSame(0, $contestant->current_question);

        $outcome = $service->submitAnswer($contestant, $competition, null, 'A');

        $this->assertSame(1, $outcome['sequence'], 'sequence is the 1-based display of the answered index');
        $this->assertSame(1, $contestant->refresh()->current_question);
    }

    public function test_a_position_cannot_be_answered_twice(): void
    {
        [$competition, $contestant, $service] = $this->startedContestant();

        $firstQuestionId = $contestant->questionIdAt(0);
        $service->submitAnswer($contestant, $competition, $firstQuestionId, 'A');

        // The same submission replayed: the index has moved, so the question it
        // names is no longer the one awaiting an answer.
        $this->expectException(ExamException::class);
        $this->expectExceptionMessageMatches('//');

        try {
            $service->submitAnswer($contestant->refresh(), $competition, $firstQuestionId, 'B');
        } catch (ExamException $e) {
            $this->assertSame('question_not_available', $e->reason);
            $this->assertSame('A', $contestant->refresh()->answerAt(0), 'the replay overwrote the answer');
            $this->assertSame(1, $contestant->current_question);

            throw $e;
        }
    }

    public function test_a_client_cannot_choose_which_question_it_answers(): void
    {
        [$competition, $contestant, $service] = $this->startedContestant();

        // Naming a question further down their own paper must not jump there.
        try {
            $service->submitAnswer($contestant, $competition, $contestant->questionIdAt(3), 'A');
            $this->fail('the client was allowed to pick its own question');
        } catch (ExamException $e) {
            $this->assertSame('question_not_available', $e->reason);
        }

        $this->assertSame(0, $contestant->refresh()->current_question);
        $this->assertSame(str_repeat(CompetitionUser::NO_ANSWER, 5), $contestant->answers);
    }

    public function test_a_contestant_cannot_answer_a_question_from_another_contestants_paper(): void
    {
        [$competition, $mine, $service] = $this->startedContestant(5);

        $theirs = $this->makeContestant($competition);
        $service->startOrResume($theirs->user, $competition);
        $theirs->refresh();

        // A question id that is on their paper at a position that is not mine.
        $foreign = collect($theirs->order())
            ->first(fn (int $id) => $id !== $mine->questionIdAt(0));

        try {
            $service->submitAnswer($mine, $competition, $foreign, 'A');
            $this->fail('a foreign question id was accepted');
        } catch (ExamException $e) {
            $this->assertSame('question_not_available', $e->reason);
        }

        $this->assertSame(0, $mine->refresh()->current_question);
        $this->assertSame(0, $theirs->refresh()->current_question);
    }

    public function test_a_fabricated_question_id_is_refused(): void
    {
        [$competition, $contestant, $service] = $this->startedContestant();

        $this->expectException(ExamException::class);

        $service->submitAnswer($contestant, $competition, 999_999, 'A');
    }

    public function test_an_answer_after_the_window_closes_is_refused_and_the_position_is_spent(): void
    {
        [$competition, $contestant, $service] = $this->startedContestant();

        $this->travel(41)->seconds();

        try {
            $service->submitAnswer($contestant, $competition, $contestant->questionIdAt(0), 'A');
            $this->fail('a late answer was accepted');
        } catch (ExamException $e) {
            $this->assertSame('question_expired', $e->reason);
        }

        $contestant->refresh();

        $this->assertNull($contestant->answerAt(0), 'a late answer was recorded');
        $this->assertGreaterThanOrEqual(1, $contestant->current_question);
        $this->assertSame(0, $contestant->answered_questions);
    }

    public function test_answering_the_last_position_finalises_the_exam_with_recomputed_totals(): void
    {
        [$competition, $contestant, $service] = $this->startedContestant(5);

        for ($position = 0; $position < 5; $position++) {
            $this->enterSlot($contestant, $competition, $position);
            $contestant->refresh();

            $option = $position % 2 === 0
                ? $this->correctOptionAt($contestant, $position)
                : $this->wrongOptionAt($contestant, $position);

            $service->submitAnswer($contestant, $competition, null, $option);
        }

        $contestant->refresh();

        $this->assertSame(CompetitionUser::EXAM_COMPLETED, $contestant->exam_status);
        $this->assertNotNull($contestant->completed_at);
        $this->assertSame(5, $contestant->current_question);
        $this->assertSame(5, $contestant->answered_questions);
        $this->assertSame(3, $contestant->correct_answers, 'positions 0, 2 and 4 were answered correctly');
        $this->assertStringNotContainsString(CompetitionUser::NO_ANSWER, $contestant->answers);
    }

    public function test_the_stored_totals_are_recomputed_rather_than_trusted(): void
    {
        [$competition, $contestant, $service] = $this->startedContestant(5);

        for ($position = 0; $position < 4; $position++) {
            $this->enterSlot($contestant, $competition, $position);
            $service->submitAnswer($contestant->refresh(), $competition, null, $this->correctOptionAt($contestant->refresh(), $position));
        }

        // Corrupt the running counters just before the finalising answer.
        $contestant->refresh()->forceFill(['correct_answers' => 99, 'answered_questions' => 99])->save();

        $this->enterSlot($contestant, $competition, 4);
        $service->submitAnswer($contestant->refresh(), $competition, null, $this->correctOptionAt($contestant->refresh(), 4));

        $contestant->refresh();

        $this->assertSame(5, $contestant->correct_answers, 'the totals were trusted rather than recomputed');
        $this->assertSame(5, $contestant->answered_questions);
    }

    public function test_a_completed_exam_refuses_further_answers(): void
    {
        [$competition, $contestant, $service] = $this->startedContestant(5);

        for ($position = 0; $position < 5; $position++) {
            $this->enterSlot($contestant, $competition, $position);
            $service->submitAnswer($contestant->refresh(), $competition, null, 'A');
        }

        try {
            $service->submitAnswer($contestant->refresh(), $competition, null, 'A');
            $this->fail('a completed exam accepted another answer');
        } catch (ExamException $e) {
            $this->assertSame('exam_completed', $e->reason);
        }
    }

    public function test_skipped_positions_count_as_unanswered_but_still_finalise_the_paper(): void
    {
        [$competition, $contestant, $service] = $this->startedContestant(5);

        $service->submitAnswer($contestant, $competition, null, $this->correctOptionAt($contestant, 0));

        // Slots 1 and 2 elapse untouched, then the contestant comes back inside
        // slot 3 and finishes the paper.
        $this->enterSlot($contestant, $competition, 3);
        $service->submitAnswer($contestant->refresh(), $competition, null, 'A');

        $this->enterSlot($contestant, $competition, 4);
        $service->submitAnswer($contestant->refresh(), $competition, null, 'A');

        $contestant->refresh();

        $this->assertSame(CompetitionUser::EXAM_COMPLETED, $contestant->exam_status);
        $this->assertSame(3, $contestant->answered_questions, 'the two elapsed positions were counted as answered');
        $this->assertSame(CompetitionUser::NO_ANSWER, substr($contestant->answers, 1, 1));
        $this->assertSame(CompetitionUser::NO_ANSWER, substr($contestant->answers, 2, 1));
    }

    public function test_finalisation_is_idempotent(): void
    {
        [$competition, $contestant, $service] = $this->startedContestant(5);

        for ($position = 0; $position < 5; $position++) {
            $this->enterSlot($contestant, $competition, $position);
            $service->submitAnswer($contestant->refresh(), $competition, null, 'A');
        }

        $completedAt = $contestant->refresh()->completed_at;
        $correct = $contestant->correct_answers;

        $service->currentQuestion($contestant->refresh(), $competition);
        $service->startOrResume($contestant->user, $competition);

        $contestant->refresh();

        $this->assertEquals($completedAt, $contestant->completed_at);
        $this->assertSame($correct, $contestant->correct_answers);
    }
}
