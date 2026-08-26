<?php

namespace Tests\Feature;

use App\Models\CompetitionQuestion;
use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Services\Competition\CompetitionExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * The state machine itself.
 *
 *   NOT_STARTED ──Begin──▶ IN_PROGRESS ──submit / elapsed time──▶ COMPLETED
 *
 * The trap this file guards is the one the business named explicitly: a
 * contestant at `in_progress` with `current_question = 0` HAS started. Reading
 * index 0 as "fresh" would restart their timeline on every request, which is
 * the most expensive bug this model could have.
 */
class ExamStateMachineTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private function exam(): CompetitionExamService
    {
        return app(CompetitionExamService::class);
    }

    /** @return array{0: CompetitionSettings, 1: CompetitionUser} */
    private function contestant(int $questions = 5): array
    {
        $settings = $this->makeCompetition(['question_count' => $questions]);
        $this->makeQuestions($settings, $questions);

        return [$settings, $this->makeContestant($settings)];
    }

    // ── NOT_STARTED ─────────────────────────────────────────────────────────

    public function test_not_started_is_the_status_and_the_absence_of_started_at(): void
    {
        [, $contestant] = $this->contestant();

        $this->assertTrue($contestant->isNotStarted());
        $this->assertSame(CompetitionUser::EXAM_NOT_STARTED, $contestant->exam_status);
        $this->assertNull($contestant->started_at);
        $this->assertFalse($contestant->hasStarted());
    }

    public function test_index_zero_alone_does_not_mean_not_started(): void
    {
        [$settings, $contestant] = $this->contestant();

        $this->exam()->startOrResume($contestant->user, $settings);
        $contestant->refresh();

        // The exact state the business warned about.
        $this->assertSame(0, $contestant->current_question);
        $this->assertSame(CompetitionUser::EXAM_IN_PROGRESS, $contestant->exam_status);

        $this->assertFalse($contestant->isNotStarted(), 'index 0 was mistaken for a fresh contestant');
        $this->assertTrue($contestant->hasStarted());
    }

    // ── Begin ───────────────────────────────────────────────────────────────

    public function test_begin_sets_started_at_exactly_once(): void
    {
        [$settings, $contestant] = $this->contestant();

        $this->exam()->startOrResume($contestant->user, $settings);
        $firstStart = $contestant->refresh()->started_at->copy();

        // Three more Begins, spread over a minute of real time.
        foreach ([10, 20, 30] as $seconds) {
            $this->travel($seconds)->seconds();
            $this->exam()->startOrResume($contestant->user, $settings);

            $this->assertEquals(
                $firstStart,
                $contestant->refresh()->started_at,
                "started_at moved on a Begin {$seconds}s later",
            );
        }
    }

    public function test_begin_sets_the_index_to_zero_and_the_status_to_in_progress(): void
    {
        [$settings, $contestant] = $this->contestant();

        $this->exam()->startOrResume($contestant->user, $settings);
        $contestant->refresh();

        $this->assertSame(0, $contestant->current_question);
        $this->assertSame(CompetitionUser::EXAM_IN_PROGRESS, $contestant->exam_status);
        $this->assertNotNull($contestant->started_at);
        $this->assertNull($contestant->completed_at);
        $this->assertCount(5, $contestant->order());
        $this->assertSame(str_repeat(CompetitionUser::NO_ANSWER, 5), $contestant->answers);
    }

    // ── current_question is an INDEX ────────────────────────────────────────

    public function test_current_question_is_an_index_into_the_order_not_a_question_id(): void
    {
        [$settings, $contestant] = $this->contestant(5);

        $this->exam()->startOrResume($contestant->user, $settings);
        $contestant->refresh();

        $order = $contestant->order();

        // Walk the whole paper and check the index against the array at every
        // position, including the one where index and id could coincide.
        foreach ($order as $index => $questionId) {
            $this->assertSame($questionId, $contestant->questionIdAt($index));
            $this->assertNotNull(CompetitionQuestion::query()->find($questionId));
        }

        // The index is small and bounded by the paper; the ids are database
        // keys. Asserting they are different values is the whole point.
        $this->assertGreaterThanOrEqual(1, min($order));
        $this->assertSame(range(0, 4), array_keys($order));
        $this->assertNotSame($order, array_keys($order), 'the order is indistinguishable from its own indices');
    }

    public function test_the_served_question_is_the_one_at_the_current_index(): void
    {
        [$settings, $contestant] = $this->contestant(5);

        $this->exam()->startOrResume($contestant->user, $settings);
        $contestant->refresh();

        foreach ([0, 1, 2, 3, 4] as $index) {
            $this->enterSlot($contestant, $settings, $index);
            $contestant->refresh();

            $payload = $this->exam()->currentQuestion($contestant, $settings);

            $this->assertSame($index, $contestant->refresh()->current_question);
            $this->assertSame(
                $contestant->questionIdAt($index),
                $payload['question_id'],
                "position {$index} served the wrong question",
            );
            $this->assertSame($index + 1, $payload['sequence'], 'sequence is the 1-based display of the index');
        }
    }

    // ── COMPLETED ───────────────────────────────────────────────────────────

    public function test_the_last_answer_moves_the_machine_to_completed(): void
    {
        [$settings, $contestant] = $this->contestant(3);

        $this->exam()->startOrResume($contestant->user, $settings);

        foreach ([0, 1, 2] as $index) {
            $this->enterSlot($contestant, $settings, $index);

            $this->assertSame(
                CompetitionUser::EXAM_IN_PROGRESS,
                $contestant->refresh()->exam_status,
                "the exam ended early, at index {$index}",
            );

            $this->exam()->submitAnswer($contestant->refresh(), $settings, null, 'A');
        }

        $contestant->refresh();

        $this->assertSame(CompetitionUser::EXAM_COMPLETED, $contestant->exam_status);
        $this->assertSame(3, $contestant->current_question, 'the index rests one past the last position');
        $this->assertNotNull($contestant->completed_at);
        $this->assertTrue($contestant->isCompleted());
        $this->assertFalse($contestant->isInProgress());
    }

    public function test_completed_is_terminal_for_beginning_again(): void
    {
        [$settings, $contestant] = $this->contestant(3);

        $this->exam()->startOrResume($contestant->user, $settings);

        foreach ([0, 1, 2] as $index) {
            $this->enterSlot($contestant, $settings, $index);
            $this->exam()->submitAnswer($contestant->refresh(), $settings, null, 'A');
        }

        $snapshot = json_encode($contestant->refresh()->only([
            'question_order', 'current_question', 'answers', 'started_at',
            'completed_at', 'correct_answers', 'answered_questions', 'exam_status',
        ]));

        $this->travel(300)->seconds();
        $this->exam()->startOrResume($contestant->user, $settings);

        $this->assertSame(
            $snapshot,
            json_encode($contestant->refresh()->only([
                'question_order', 'current_question', 'answers', 'started_at',
                'completed_at', 'correct_answers', 'answered_questions', 'exam_status',
            ])),
            'beginning again changed a completed exam',
        );
    }

    // ── the HTTP surface reports the same machine ───────────────────────────

    public function test_the_envelope_reports_each_state_over_http(): void
    {
        [$settings, $contestant] = $this->contestant(2);

        // NOT_STARTED: no question, and reading does not start anything.
        $this->actingAs($contestant->user)
            ->getJson('/api/exam/current')
            ->assertOk()
            ->assertJsonPath('exam_status', CompetitionUser::EXAM_NOT_STARTED)
            ->assertJsonPath('question', null)
            ->assertJsonPath('waiting', null)
            ->assertJsonPath('started_at', null);

        $this->assertTrue($contestant->refresh()->isNotStarted());

        // IN_PROGRESS: a question, and an anchor.
        $started = $this->actingAs($contestant->user)->postJson('/api/exam/start')->assertOk();

        $started->assertJsonPath('exam_status', CompetitionUser::EXAM_IN_PROGRESS)
            ->assertJsonPath('question.sequence', 1)
            ->assertJsonPath('waiting', null);

        $this->assertNotNull($started->json('started_at'));

        // COMPLETED: neither a question nor a wait.
        foreach ([0, 1] as $index) {
            $this->enterSlot($contestant, $settings, $index);

            $this->actingAs($contestant->user)->postJson('/api/exam/answer', [
                'selected_option' => 'A',
            ])->assertOk();
        }

        $this->actingAs($contestant->user)
            ->getJson('/api/exam/current')
            ->assertOk()
            ->assertJsonPath('exam_status', CompetitionUser::EXAM_COMPLETED)
            ->assertJsonPath('question', null)
            ->assertJsonPath('waiting', null);
    }
}
