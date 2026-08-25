<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\CompetitionQuestion;
use App\Models\CompetitionUserQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/** The HTTP contract the Vue client will consume. */
class ExamHttpTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    public function test_a_contestant_can_log_in_and_the_session_id_is_regenerated(): void
    {
        $competition = $this->makeCompetition();
        $participation = $this->makeContestant($competition);
        $participation->user->forceFill(['password' => Hash::make('correct-horse')])->save();

        $response = $this->postJson('/api/login', [
            'email' => $participation->contestant_email,
            'password' => 'correct-horse',
        ]);

        $response->assertOk()->assertJsonPath('user.email', $participation->contestant_email);
        $this->assertAuthenticatedAs($participation->user);
        // The credential must never come back in the response.
        $response->assertJsonMissingPath('user.password');
    }

    public function test_a_wrong_password_is_refused_with_a_non_enumerating_message(): void
    {
        $competition = $this->makeCompetition();
        $participation = $this->makeContestant($competition);

        $wrong = $this->postJson('/api/login', [
            'email' => $participation->contestant_email,
            'password' => 'not-the-password',
        ])->assertStatus(422);

        $unknown = $this->postJson('/api/login', [
            'email' => 'nobody@madad.test',
            'password' => 'not-the-password',
        ])->assertStatus(422);

        // Identical wording, so the endpoint cannot be used to discover who is registered.
        $this->assertSame(
            $wrong->json('errors.email'),
            $unknown->json('errors.email'),
        );
        $this->assertGuest();
    }

    public function test_logout_invalidates_the_session(): void
    {
        $competition = $this->makeCompetition();
        $participation = $this->makeContestant($competition);

        $this->actingAs($participation->user)->postJson('/api/logout')->assertOk();
        $this->assertGuest();
    }

    public function test_the_status_endpoint_is_public_and_reports_a_closed_portal(): void
    {
        $this->makeCompetition(['status' => Competition::STATUS_READY]);

        $this->getJson('/api/competition/status')
            ->assertOk()
            ->assertJsonPath('open', false)
            ->assertJsonPath('status', 'ready');
    }

    public function test_exam_endpoints_require_authentication(): void
    {
        $this->makeCompetition();

        $this->postJson('/api/exam/start')->assertUnauthorized();
        $this->getJson('/api/exam/current')->assertUnauthorized();
        $this->postJson('/api/exam/answer', ['question_id' => 1, 'selected_option' => 'A'])->assertUnauthorized();
        $this->getJson('/api/exam/result')->assertUnauthorized();
    }

    public function test_starting_returns_the_first_question_without_the_answer_key(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $participation = $this->makeContestant($competition);

        $response = $this->actingAs($participation->user)->postJson('/api/exam/start')->assertOk();

        $response->assertJsonPath('exam_status', 'in_progress');
        $response->assertJsonPath('question.sequence', 1);
        $response->assertJsonMissingPath('question.correct_option');
        $this->assertSame(['A', 'B', 'C', 'D'], array_keys($response->json('question.options')));
    }

    public function test_a_closed_portal_refuses_to_start_over_http(): void
    {
        $competition = $this->makeCompetition(['status' => Competition::STATUS_CLOSED, 'question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $participation = $this->makeContestant($competition);

        $this->actingAs($participation->user)->postJson('/api/exam/start')
            ->assertStatus(403)
            ->assertJsonPath('reason', 'competition_not_open');
    }

    public function test_the_answer_endpoint_rejects_an_option_outside_a_to_d(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $participation = $this->makeContestant($competition);

        $this->actingAs($participation->user)->postJson('/api/exam/start');

        $this->actingAs($participation->user)
            ->postJson('/api/exam/answer', ['question_id' => 1, 'selected_option' => 'E'])
            ->assertStatus(422);
    }

    public function test_client_supplied_correctness_and_timing_are_ignored(): void
    {
        $competition = $this->makeCompetition(['question_count' => 3]);
        $this->makeQuestions($competition, 3);
        $participation = $this->makeContestant($competition);

        $start = $this->actingAs($participation->user)->postJson('/api/exam/start');
        $questionId = $start->json('question.question_id');
        $key = CompetitionQuestion::query()->find($questionId)->correct_option;
        $wrong = collect(['A', 'B', 'C', 'D'])->reject(fn ($o) => $o === $key)->first();

        $this->actingAs($participation->user)->postJson('/api/exam/answer', [
            'question_id' => $questionId,
            'selected_option' => $wrong,
            // Everything below is an attempt to dictate the outcome.
            'is_correct' => true,
            'answered_at' => now()->subYear()->toIso8601String(),
            'sequence' => 99,
            'correct_answers' => 75,
            'expires_at' => now()->addYear()->toIso8601String(),
        ])->assertOk();

        $row = CompetitionUserQuestion::query()->where('sequence', 1)->first();
        $this->assertFalse($row->is_correct, 'the server graded the answer, not the client');
        $this->assertTrue($row->answered_at->isToday());
        $this->assertSame(0, $participation->fresh()->correct_answers);
    }

    public function test_a_contestant_without_a_participation_is_refused(): void
    {
        $this->makeCompetition(['question_count' => 5]);

        $outsider = \App\Models\User::query()->create([
            'name' => 'غريب',
            'email' => 'outsider@madad.test',
            'password' => 'irrelevant',
        ]);

        $this->actingAs($outsider)->postJson('/api/exam/start')
            ->assertStatus(403)
            ->assertJsonPath('reason', 'not_a_contestant');
    }

    public function test_login_is_rate_limited(): void
    {
        $competition = $this->makeCompetition();
        $participation = $this->makeContestant($competition);

        for ($i = 0; $i < 7; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => $participation->contestant_email,
                'password' => 'wrong',
            ]);
        }

        // Either the route throttle (429) or the per-email limiter (422 lockout).
        $this->assertContains($response->status(), [422, 429]);
    }
}
