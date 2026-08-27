<?php

namespace Tests\Feature;

use App\Models\CompetitionQuestion;
use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * The whole contestant journey, driven entirely through the HTTP layer.
 *
 * Nothing here calls a service directly. Every step is a real request through
 * real routing, real session authentication, real form-request validation and
 * the real JSON envelope — because a suite that only exercises services proves
 * the engine works and says nothing about whether the application does.
 */
class ContestantFlowHttpTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private const PASSWORD = 'contestant-password';

    /** @return array{0: CompetitionSettings, 1: CompetitionUser} */
    private function portal(int $questions = 5): array
    {
        $competition = $this->makeCompetition([
            'question_count' => $questions,
            'seconds_per_question' => 40,
            'show_result' => true,
        ]);
        $this->makeQuestions($competition, $questions);

        $participation = $this->makeContestant($competition);
        $participation->user->forceFill(['password' => Hash::make(self::PASSWORD)])->save();

        return [$competition, $participation];
    }

    /**
     * login → status → start → current → answer → next → timeout → continue →
     * final question → completion → result → logout.
     *
     * Deliberately one long test: the point being proved is that the steps
     * compose across a single session, which splitting them up would hide.
     */
    public function test_the_complete_contestant_flow_works_over_http(): void
    {
        // The clock is frozen because this case asserts remaining seconds
        // exactly (35.0 after a 45-second timeout). Real time spent inside the
        // request cycle comes off that number, so on slower hardware the
        // assertion measures the machine rather than the engine. travel() still
        // advances the clock where the test means to.
        $this->freezeTime();

        [$competition, $participation] = $this->portal(5);

        // ── login ───────────────────────────────────────────────────────────
        $this->postJson('/api/login', [
            'email' => $participation->contestant_email,
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('user.email', $participation->contestant_email);

        $this->assertAuthenticatedAs($participation->user);

        // ── status ──────────────────────────────────────────────────────────
        $this->getJson('/api/competition/status')
            ->assertOk()
            ->assertJsonPath('open', true)
            ->assertJsonPath('reason', null)
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('total_questions', 5)
            ->assertJsonPath('seconds_per_question', 40)
            ->assertJsonPath('participation.exam_status', CompetitionUser::EXAM_NOT_STARTED);

        // ── start ───────────────────────────────────────────────────────────
        $start = $this->postJson('/api/exam/start')->assertOk();
        $start->assertJsonPath('exam_status', CompetitionUser::EXAM_IN_PROGRESS);
        $this->assertNotNull($start->json('started_at'));

        $first = $start->json('question');
        $this->assertSame(1, $first['sequence']);
        $this->assertSame(5, $first['total_questions']);
        $this->assertPayloadShape($first);

        // ── current: the same question, with the same deadline ──────────────
        $current = $this->getJson('/api/exam/current')->assertOk();
        $this->assertSame($first['question_id'], $current->json('question.question_id'));
        $this->assertSame($first['expires_at'], $current->json('question.expires_at'), 'a refresh must not move the deadline');

        // ── answer question 1 ───────────────────────────────────────────────
        $answer = $this->postJson('/api/exam/answer', [
            'question_id' => $first['question_id'],
            'selected_option' => $this->keyFor($first['question_id']),
        ])->assertOk();

        $answer->assertJsonPath('accepted', true)->assertJsonPath('sequence', 1);
        // The response must not say whether the answer was right.
        $answer->assertJsonMissingPath('is_correct');
        $answer->assertJsonMissingPath('correct_option');

        // ── the next question comes back with the answer, immediately ────────
        $second = $answer->json('next_question');

        $this->assertNotNull($second, 'the contestant was made to wait after answering');
        $answer->assertJsonMissingPath('waiting');
        $this->assertSame(2, $second['sequence']);
        $this->assertPayloadShape($second);
        $this->assertEqualsWithDelta(40.0, $second['seconds_remaining'], 1.0, 'the new question was short-changed');

        // A read confirms the same live question and the same deadline.
        $this->assertSame(
            $second['expires_at'],
            $this->getJson('/api/exam/current')->assertOk()->json('question.expires_at'),
        );

        // ── timeout path: let question 2's window elapse, then answer it late ─
        $this->travel(45)->seconds();

        $this->postJson('/api/exam/answer', [
            'question_id' => $second['question_id'],
            'selected_option' => 'A',
        ])->assertStatus(422)->assertJsonPath('reason', 'question_expired');

        $participation->refresh();
        $this->assertNull($participation->answerAt(1), 'a late answer was recorded');
        $this->assertGreaterThanOrEqual(2, $participation->current_question);

        // ── continue: the exam is still live and serves question 3 ──────────
        $third = $this->getJson('/api/exam/current')->assertOk()->json('question');
        $this->assertSame(3, $third['sequence']);
        // Question 3 opened when question 2's window CLOSED, forty seconds after
        // the first answer — not five seconds later when we noticed. So 35 of
        // its 40 seconds remain, and a reload does not buy a fresh forty.
        $this->assertSame(35.0, round($third['seconds_remaining']));
        $this->assertLessThanOrEqual(40.0, $third['seconds_remaining']);

        // ── answer 3, 4 and 5 back to back, each served by its own answer ────
        $question = $third;

        for ($sequence = 3; $sequence <= 5; $sequence++) {
            $this->assertSame($sequence, $question['sequence']);

            $next = $this->postJson('/api/exam/answer', [
                'question_id' => $question['question_id'],
                'selected_option' => $this->keyFor($question['question_id']),
            ])->assertOk();

            if ($sequence === 5) {
                $this->assertNull($next->json('next_question'), 'a question followed the last one');

                break;
            }

            $question = $next->json('next_question');
            $this->assertNotNull($question, "answering {$sequence} did not open the next question");
        }

        // ── completion ──────────────────────────────────────────────────────
        $this->assertNull(
            $this->getJson('/api/exam/current')->assertOk()->json('question'),
            'there is no question after the last one',
        );

        $final = $this->getJson('/api/exam/current')->assertOk();
        $final->assertJsonPath('exam_status', CompetitionUser::EXAM_COMPLETED);
        $final->assertJsonPath('question', null);

        // ── result ──────────────────────────────────────────────────────────
        $result = $this->getJson('/api/exam/result')->assertOk();
        $result->assertJsonPath('exam_status', CompetitionUser::EXAM_COMPLETED);
        $result->assertJsonPath('show_result', true);
        $result->assertJsonPath('total_questions', 5);
        // Four answered correctly, one lost to the timeout.
        $result->assertJsonPath('correct_answers', 4);
        $result->assertJsonPath('answered_questions', 4);
        $this->assertNotNull($result->json('completed_at'));

        // The stored row must agree with what was reported.
        $participation->refresh();
        $this->assertSame(4, $participation->correct_answers);
        $this->assertSame(4, $participation->answered_questions);

        // ── logout ──────────────────────────────────────────────────────────
        $this->postJson('/api/logout')->assertOk();
        $this->assertGuest();

        $this->getJson('/api/exam/current')
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'unauthenticated');
    }

    public function test_a_contestant_resumes_the_same_question_after_logging_back_in(): void
    {
        $this->freezeTime();

        [, $participation] = $this->portal(4);

        $this->actingAs($participation->user);
        $first = $this->postJson('/api/exam/start')->assertOk()->json('question');

        $this->postJson('/api/logout')->assertOk();
        $this->assertGuest();

        $this->postJson('/api/login', [
            'email' => $participation->contestant_email,
            'password' => self::PASSWORD,
        ])->assertOk();

        // Resuming is the same call as starting, and must not reshuffle or
        // restart anything.
        $resumed = $this->postJson('/api/exam/start')->assertOk();
        $resumed->assertJsonPath('exam_status', CompetitionUser::EXAM_IN_PROGRESS);

        $this->assertSame($first['question_id'], $resumed->json('question.question_id'));
        $this->assertSame($first['sequence'], $resumed->json('question.sequence'));
        $this->assertSame($first['expires_at'], $resumed->json('question.expires_at'), 'a re-login must not buy more time');
    }

    public function test_the_question_payload_exposes_exactly_the_agreed_keys(): void
    {
        $this->freezeTime();

        [, $participation] = $this->portal(3);

        $payload = $this->actingAs($participation->user)
            ->postJson('/api/exam/start')->assertOk()->json('question');

        // Exactly these keys — an extra one is a leak waiting to happen, and a
        // missing one breaks the client.
        $this->assertSame([
            'question_id',
            'question_text',
            'options',
            'sequence',
            'total_questions',
            'opened_at',
            'expires_at',
            'server_time',
            'seconds_remaining',
        ], array_keys($payload));

        $this->assertSame(['A', 'B', 'C', 'D'], array_keys($payload['options']));
    }

    public function test_no_response_in_the_whole_flow_carries_the_answer_key(): void
    {
        [, $participation] = $this->portal(3);
        $this->actingAs($participation->user);

        $bodies = [];
        $bodies[] = $this->getJson('/api/competition/status')->content();
        $start = $this->postJson('/api/exam/start');
        $bodies[] = $start->content();
        $bodies[] = $this->getJson('/api/exam/current')->content();

        $question = $start->json('question');
        $bodies[] = $this->postJson('/api/exam/answer', [
            'question_id' => $question['question_id'],
            'selected_option' => 'A',
        ])->content();
        $bodies[] = $this->getJson('/api/exam/result')->content();

        foreach ($bodies as $body) {
            $this->assertStringNotContainsString('correct_option', $body);
            $this->assertStringNotContainsString('is_correct', $body);
            $this->assertStringNotContainsString('password', $body);
        }
    }

    // ────────────────────────────────────────────────────────────── helpers ──

    private function keyFor(int $questionId): string
    {
        return CompetitionQuestion::query()->findOrFail($questionId)->correct_option;
    }

    /** @param  array<string, mixed>  $payload */
    private function assertPayloadShape(array $payload): void
    {
        $this->assertArrayNotHasKey('correct_option', $payload);
        $this->assertArrayNotHasKey('is_correct', $payload);
        $this->assertArrayNotHasKey('competition_user_id', $payload);
        $this->assertNotNull($payload['opened_at']);
        $this->assertNotNull($payload['expires_at']);
        $this->assertNotNull($payload['server_time']);
        $this->assertGreaterThan(0, $payload['seconds_remaining']);
    }
}
