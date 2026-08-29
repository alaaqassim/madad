<?php

namespace Tests\Feature;

use App\Models\CompetitionUser;
use App\Services\Competition\CompetitionExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * The contestant's paper is one randomised array of question ids, persisted
 * once on their participation row.
 *
 * The properties that matter are all about stability: an order that could be
 * regenerated is an order a contestant could reroll until it suited them, and
 * an order that leaked would hand out the shape of everyone else's paper.
 */
class QuestionOrderTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private function exam(): CompetitionExamService
    {
        return app(CompetitionExamService::class);
    }

    public function test_starting_persists_an_order_of_every_question_exactly_once(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $questions = $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);

        $order = $contestant->refresh()->order();

        $this->assertCount(5, $order);
        $this->assertSame($order, array_values(array_unique($order)), 'the order repeats a question');
        $this->assertEqualsCanonicalizing(
            array_map(fn ($q) => $q->id, $questions),
            $order,
            'the order is not a permutation of the question bank',
        );
    }

    public function test_the_order_holds_real_question_ids(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $contestant->refresh();

        foreach ($contestant->order() as $index => $questionId) {
            $this->assertSame($questionId, $contestant->questionIdAt($index));
            $this->assertDatabaseHas('competition_questions', [
                'id' => $questionId,
            ]);
        }
    }

    /**
     * Ids are not a counting sequence and the paper must not assume they are.
     *
     * A bank is edited: a question is imported, another is removed, the table
     * is reloaded. What survives is a set of ids with holes in it that need not
     * begin at 1. Anything that built a paper from range(1, n) would deal
     * questions that do not exist and skip ones that do - so the paper is built
     * from the ids the bank actually holds, and this proves it on a bank shaped
     * like one that has been edited.
     */
    public function test_a_paper_is_built_from_the_ids_the_bank_holds_not_from_a_range(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $questions = $this->makeQuestions($competition, 12);

        // Punch holes: keep the 2nd, 5th, 6th, 9th, 11th and 12th. The ids that
        // remain neither start at 1 nor run consecutively.
        $keep = [1, 4, 5, 8, 10, 11];

        foreach ($questions as $position => $question) {
            if (! in_array($position, $keep, true)) {
                $question->delete();
            }
        }

        $survivingIds = array_map(fn ($i) => $questions[$i]->id, $keep);
        sort($survivingIds);

        $this->assertNotSame(range(1, count($survivingIds)), $survivingIds, 'the fixture failed to create gaps');

        $contestant = $this->makeContestant($competition);
        $this->exam()->startOrResume($contestant->user, $competition);

        $order = $contestant->refresh()->order();

        $this->assertCount(5, $order);
        $this->assertEmpty(
            array_diff($order, $survivingIds),
            'the paper holds an id that is not in the bank - it was built from a range, not from the table',
        );
    }

    public function test_a_paper_built_over_gaps_still_serves_the_right_questions(): void
    {
        // The order surviving is not enough: every id in it has to resolve to a
        // real question when the contestant reaches that position.
        $competition = $this->makeCompetition(['question_count' => 3]);
        $questions = $this->makeQuestions($competition, 9);

        foreach ([0, 2, 4, 6] as $position) {
            $questions[$position]->delete();
        }

        $contestant = $this->makeContestant($competition);
        $this->exam()->startOrResume($contestant->user, $competition);
        $contestant->refresh();

        foreach ($contestant->order() as $position => $questionId) {
            $served = $this->exam()->state($contestant->refresh(), $competition)['question'];

            $this->assertSame($questionId, $served['question_id'], "position {$position} served the wrong question");
            $this->assertNotEmpty($served['question_text']);

            $this->exam()->submitAnswer($contestant, $competition, $questionId, 'A');
        }

        $this->assertTrue($contestant->refresh()->isCompleted());
    }

    public function test_a_second_start_does_not_reshuffle(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $first = $contestant->refresh()->order();

        $this->exam()->startOrResume($contestant->user, $competition);
        $this->exam()->startOrResume($contestant->user, $competition);

        $this->assertSame($first, $contestant->refresh()->order());
    }

    public function test_a_refresh_and_a_re_login_do_not_reshuffle(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->actingAs($contestant->user)->postJson('/api/exam/start')->assertOk();
        $order = $contestant->refresh()->order();

        $this->actingAs($contestant->user)->getJson('/api/exam/current')->assertOk();
        $this->post('/api/logout');

        $this->actingAs($contestant->user)->postJson('/api/exam/start')->assertOk();

        $this->assertSame($order, $contestant->refresh()->order());
    }

    public function test_two_contestants_get_different_orders(): void
    {
        $competition = $this->makeCompetition(['question_count' => 25]);
        $this->makeQuestions($competition, 25);

        $orders = [];

        for ($i = 0; $i < 6; $i++) {
            $contestant = $this->makeContestant($competition);
            $this->exam()->startOrResume($contestant->user, $competition);
            $orders[] = implode(',', $contestant->refresh()->order());
        }

        // With 25! permutations, six identical draws would not be chance.
        $this->assertGreaterThan(1, count(array_unique($orders)), 'every contestant received the same order');
    }

    public function test_the_order_is_never_serialised_to_the_contestant(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $start = $this->actingAs($contestant->user)->postJson('/api/exam/start')->assertOk();
        $order = $contestant->refresh()->order();

        $body = $start->getContent();

        $this->assertStringNotContainsString('question_order', $body);
        $this->assertStringNotContainsString('correct_option', $body);
        $this->assertStringNotContainsString('answers', $body);

        // The current question's id is legitimately present; the rest must not be.
        foreach (array_slice($order, 1) as $hidden) {
            $this->assertStringNotContainsString('"'.$hidden.'"', $body);
        }
    }

    public function test_a_short_question_bank_refuses_rather_than_dealing_a_short_paper(): void
    {
        $competition = $this->makeCompetition(['question_count' => 10]);
        $this->makeQuestions($competition, 4);
        $contestant = $this->makeContestant($competition);

        $this->actingAs($contestant->user)
            ->postJson('/api/exam/start')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'paper_not_ready');

        $this->assertNull($contestant->refresh()->question_order);
        $this->assertSame(CompetitionUser::EXAM_NOT_STARTED, $contestant->exam_status);
    }

    public function test_the_answer_string_starts_blank_and_matches_the_order_length(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->exam()->startOrResume($contestant->user, $competition);
        $contestant->refresh();

        $this->assertSame(str_repeat(CompetitionUser::NO_ANSWER, 5), $contestant->answers);
        $this->assertSame(0, $contestant->current_question);
    }
}
