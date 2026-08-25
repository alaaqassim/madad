<?php

namespace Tests\Feature;

use App\Exceptions\ExamException;
use App\Models\CompetitionQuestion;
use App\Models\CompetitionUser;
use App\Models\CompetitionUserQuestion;
use App\Services\Competition\CompetitionExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

class AnswerSubmissionTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

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
        [, $participation, $service] = $this->startedContestant();

        $question = $service->currentQuestion($participation);
        $key = CompetitionQuestion::query()->find($question['question_id'])->correct_option;

        $service->submitAnswer($participation->fresh(), $question['question_id'], $key);

        $row = CompetitionUserQuestion::query()->where('sequence', 1)->first();
        $this->assertTrue($row->is_correct);
        $this->assertSame($key, $row->selected_option);
        $this->assertFalse($row->timed_out);
    }

    public function test_a_wrong_answer_is_graded_incorrect(): void
    {
        [, $participation, $service] = $this->startedContestant();

        $question = $service->currentQuestion($participation);
        $key = CompetitionQuestion::query()->find($question['question_id'])->correct_option;
        $wrong = collect(['A', 'B', 'C', 'D'])->reject(fn ($o) => $o === $key)->first();

        $service->submitAnswer($participation->fresh(), $question['question_id'], $wrong);

        $row = CompetitionUserQuestion::query()->where('sequence', 1)->first();
        $this->assertFalse($row->is_correct);
        $this->assertSame($wrong, $row->selected_option);
    }

    public function test_the_response_does_not_tell_the_contestant_whether_they_were_right(): void
    {
        [, $participation, $service] = $this->startedContestant();

        $question = $service->currentQuestion($participation);
        $key = CompetitionQuestion::query()->find($question['question_id'])->correct_option;

        $outcome = $service->submitAnswer($participation->fresh(), $question['question_id'], $key);

        // Returning correctness per answer would make the exam an oracle for
        // the answer key.
        $this->assertArrayNotHasKey('is_correct', $outcome);
        $this->assertArrayNotHasKey('correct_option', $outcome);
    }

    public function test_answering_advances_to_the_next_question(): void
    {
        [, $participation, $service] = $this->startedContestant();

        $first = $service->currentQuestion($participation);
        $service->submitAnswer($participation->fresh(), $first['question_id'], 'A');
        $second = $service->currentQuestion($participation->fresh());

        $this->assertSame(2, $second['sequence']);
        $this->assertNotSame($first['question_id'], $second['question_id']);
    }

    public function test_a_question_cannot_be_answered_twice(): void
    {
        [, $participation, $service] = $this->startedContestant();

        $question = $service->currentQuestion($participation);
        $service->submitAnswer($participation->fresh(), $question['question_id'], 'A');

        try {
            $service->submitAnswer($participation->fresh(), $question['question_id'], 'B');
            $this->fail('a finalised question must not accept a second answer');
        } catch (ExamException $e) {
            $this->assertSame('question_not_available', $e->reason);
        }

        $this->assertSame('A', CompetitionUserQuestion::query()->where('sequence', 1)->value('selected_option'));
    }

    public function test_a_contestant_cannot_answer_a_question_from_another_contestants_paper(): void
    {
        // A bank of 12 for papers of 5, so the two papers are genuinely
        // different subsets and a question always exists on theirs but not on
        // mine. Nothing here depends on how the shuffle happened to fall.
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 12);
        $mine = $this->makeContestant($competition);
        $theirs = $this->makeContestant($competition);
        $service = app(CompetitionExamService::class);

        $service->startOrResume($mine->user, $competition);
        $service->startOrResume($theirs->user, $competition);
        $service->currentQuestion($mine->fresh());

        $myPaper = CompetitionUserQuestion::query()
            ->where('competition_user_id', $mine->id)->pluck('competition_question_id')->all();

        $onlyTheirs = CompetitionUserQuestion::query()
            ->where('competition_user_id', $theirs->id)
            ->whereNotIn('competition_question_id', $myPaper)
            ->value('competition_question_id');

        $this->assertNotNull($onlyTheirs, 'the two papers must differ for this test to mean anything');

        try {
            $service->submitAnswer($mine->fresh(), $onlyTheirs, 'A');
            $this->fail('answering another paper must be refused');
        } catch (ExamException $e) {
            // Identical message whether the question belongs to someone else or
            // simply is not current — no oracle.
            $this->assertSame('question_not_available', $e->reason);
        }

        $this->assertNull(CompetitionUserQuestion::query()
            ->where('competition_user_id', $theirs->id)
            ->where('sequence', 1)->value('selected_option'));
    }

    public function test_a_fabricated_question_id_is_refused(): void
    {
        [, $participation, $service] = $this->startedContestant();
        $service->currentQuestion($participation);

        $this->expectException(ExamException::class);
        $service->submitAnswer($participation->fresh(), 999999, 'A');
    }

    public function test_answering_the_last_question_finalises_the_exam_with_recomputed_totals(): void
    {
        [, $participation, $service] = $this->startedContestant(3);

        $expectedCorrect = 0;

        for ($i = 0; $i < 3; $i++) {
            $question = $service->currentQuestion($participation->fresh());
            $key = CompetitionQuestion::query()->find($question['question_id'])->correct_option;
            // Answer the first two correctly, the last one wrong.
            $answer = $i < 2 ? $key : collect(['A', 'B', 'C', 'D'])->reject(fn ($o) => $o === $key)->first();
            $expectedCorrect += $i < 2 ? 1 : 0;
            $service->submitAnswer($participation->fresh(), $question['question_id'], $answer);
        }

        $participation->refresh();
        $this->assertSame(CompetitionUser::EXAM_COMPLETED, $participation->exam_status);
        $this->assertNotNull($participation->completed_at);
        $this->assertSame($expectedCorrect, $participation->correct_answers);
        $this->assertSame(3, $participation->answered_questions);

        // The stored aggregate must equal the rows it summarises.
        $actual = CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)->where('is_correct', true)->count();
        $this->assertSame($actual, $participation->correct_answers);
    }

    public function test_finalisation_is_idempotent(): void
    {
        [, $participation, $service] = $this->startedContestant(2);

        for ($i = 0; $i < 2; $i++) {
            $q = $service->currentQuestion($participation->fresh());
            $service->submitAnswer($participation->fresh(), $q['question_id'], 'A');
        }

        $completedAt = $participation->fresh()->completed_at;
        $correct = $participation->fresh()->correct_answers;

        $this->travel(1)->minute();

        // Repeated reads must not move the finish line or the score.
        $this->assertNull($service->currentQuestion($participation->fresh()));
        $this->assertNull($service->currentQuestion($participation->fresh()));

        $this->assertEquals($completedAt, $participation->fresh()->completed_at);
        $this->assertSame($correct, $participation->fresh()->correct_answers);
    }

    public function test_a_completed_exam_refuses_further_answers(): void
    {
        [, $participation, $service] = $this->startedContestant(1);

        $q = $service->currentQuestion($participation->fresh());
        $service->submitAnswer($participation->fresh(), $q['question_id'], 'A');

        $this->expectException(ExamException::class);
        $service->submitAnswer($participation->fresh(), $q['question_id'], 'B');
    }

    public function test_timed_out_questions_count_as_unanswered_but_still_finalise_the_paper(): void
    {
        [, $participation, $service] = $this->startedContestant(2);

        $first = $service->currentQuestion($participation->fresh());
        $service->submitAnswer($participation->fresh(), $first['question_id'], 'A');

        $service->currentQuestion($participation->fresh());
        $this->travel(41)->seconds();
        $this->assertNull($service->currentQuestion($participation->fresh()));

        $participation->refresh();
        $this->assertSame(CompetitionUser::EXAM_COMPLETED, $participation->exam_status);
        $this->assertSame(1, $participation->answered_questions, 'a timeout is not an answer');
    }
}
