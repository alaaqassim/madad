<?php

namespace Tests\Feature;

use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * The error contract the Vue client will be written against.
 *
 * Every JSON error carries a stable `reason` and a documented HTTP status. This
 * test is the lock on both: changing a code or a status here is a breaking
 * change to the frontend, and must be a deliberate one.
 *
 * It also asserts the negative half of the contract — that no error body ever
 * carries SQL, a stack trace, a model class name, or an answer-key clue.
 */
class ErrorContractTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
    }

    public function test_unauthenticated_requests_are_consistent_across_every_protected_route(): void
    {
        $this->makeCompetition();

        $calls = [
            fn () => $this->postJson('/api/logout'),
            fn () => $this->postJson('/api/exam/start'),
            fn () => $this->getJson('/api/exam/current'),
            fn () => $this->postJson('/api/exam/answer', ['question_id' => 1, 'selected_option' => 'A']),
            fn () => $this->getJson('/api/exam/result'),
        ];

        foreach ($calls as $call) {
            $call()->assertStatus(401)->assertJsonPath('reason', 'unauthenticated');
        }
    }

    public function test_invalid_credentials_carry_a_stable_code(): void
    {
        $competition = $this->makeCompetition();
        $participation = $this->makeContestant($competition);

        $this->postJson('/api/login', [
            'email' => $participation->contestant_email,
            'password' => 'wrong',
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_credentials')
            // Laravel's per-field shape is retained, so the client can still
            // render a message under the input.
            ->assertJsonStructure(['message', 'reason', 'errors' => ['email']]);
    }

    public function test_a_malformed_request_is_a_validation_error_not_a_credential_error(): void
    {
        $competition = $this->makeCompetition();
        $participation = $this->makeContestant($competition);

        // Missing password — never reaches the credential check.
        $this->postJson('/api/login', ['email' => $participation->contestant_email])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'validation_error');

        $this->actingAs($participation->user)
            ->postJson('/api/exam/answer', ['question_id' => 1, 'selected_option' => 'E'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'validation_error');
    }

    public function test_repeated_failed_logins_report_too_many_attempts(): void
    {
        $competition = $this->makeCompetition();
        $participation = $this->makeContestant($competition);

        $response = null;

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $response = $this->postJson('/api/login', [
                'email' => $participation->contestant_email,
                'password' => 'wrong',
            ]);
        }

        // Either limiter may fire first; both must speak the same code.
        $this->assertContains($response->status(), [422, 429]);
        $this->assertSame('too_many_attempts', $response->json('reason'));
    }

    public function test_every_exam_refusal_uses_its_documented_code_and_status(): void
    {
        $competition = $this->makeCompetition(['question_count' => 2]);
        $this->makeQuestions($competition, 2);
        $participation = $this->makeContestant($competition);

        // ── not_a_contestant, 403 ───────────────────────────────────────────
        $outsider = User::query()->create([
            'name' => 'غريب', 'email' => 'outsider@madad.test', 'password' => Hash::make('x'),
        ]);
        $this->actingAs($outsider)->postJson('/api/exam/start')
            ->assertStatus(403)->assertJsonPath('reason', 'not_a_contestant');

        // ── account_not_provisioned, 403 ────────────────────────────────────
        $unprovisioned = $this->makeContestant($competition);
        $unprovisioned->forceFill(['account_status' => CompetitionUser::ACCOUNT_PENDING])->save();
        $this->actingAs($unprovisioned->user)->postJson('/api/exam/start')
            ->assertStatus(403)->assertJsonPath('reason', 'account_not_provisioned');

        // ── question_not_available, 422 ─────────────────────────────────────
        $this->actingAs($participation->user);
        $question = $this->postJson('/api/exam/start')->assertOk()->json('question');

        $this->postJson('/api/exam/answer', ['question_id' => 987654, 'selected_option' => 'A'])
            ->assertStatus(422)->assertJsonPath('reason', 'question_not_available');

        // ── question_expired, 422 ───────────────────────────────────────────
        $this->travel(41)->seconds();
        $this->postJson('/api/exam/answer', [
            'question_id' => $question['question_id'], 'selected_option' => 'A',
        ])->assertStatus(422)->assertJsonPath('reason', 'question_expired');

        // ── exam_completed, 409 ─────────────────────────────────────────────
        $next = $this->getJson('/api/exam/current')->assertOk()->json('question');
        $this->postJson('/api/exam/answer', [
            'question_id' => $next['question_id'], 'selected_option' => 'A',
        ])->assertOk();

        $this->postJson('/api/exam/answer', [
            'question_id' => $next['question_id'], 'selected_option' => 'B',
        ])->assertStatus(409)->assertJsonPath('reason', 'exam_completed');
    }

    public function test_paper_not_ready_is_reported_when_the_bank_is_short(): void
    {
        // A paper of 10 with only 3 questions in the bank: refuse rather than
        // hand out a short paper.
        $competition = $this->makeCompetition(['question_count' => 10]);
        $this->makeQuestions($competition, 3);
        $participation = $this->makeContestant($competition);

        $this->actingAs($participation->user)->postJson('/api/exam/start')
            ->assertStatus(409)->assertJsonPath('reason', 'paper_not_ready');
    }

    public function test_competition_not_open_and_competition_closed_are_distinct_codes(): void
    {
        $competition = $this->makeCompetition(['status' => CompetitionSettings::STATUS_DRAFT, 'question_count' => 2]);
        $this->makeQuestions($competition, 2);
        $participation = $this->makeContestant($competition);

        $this->actingAs($participation->user)->postJson('/api/exam/start')
            ->assertStatus(403)->assertJsonPath('reason', 'competition_not_open');

        $competition->forceFill(['status' => CompetitionSettings::STATUS_CLOSED])->save();

        $this->actingAs($participation->user)->postJson('/api/exam/start')
            ->assertStatus(403)->assertJsonPath('reason', 'competition_closed');
    }

    public function test_an_unknown_api_route_returns_the_json_contract_not_an_html_page(): void
    {
        $this->getJson('/api/exam/there-is-no-such-thing')
            ->assertStatus(404)
            ->assertJsonPath('reason', 'not_found');
    }

    public function test_no_error_body_leaks_internals(): void
    {
        $competition = $this->makeCompetition(['question_count' => 2]);
        $this->makeQuestions($competition, 2);
        $participation = $this->makeContestant($competition);

        $bodies = [
            $this->postJson('/api/login', ['email' => 'nobody@madad.test', 'password' => 'x'])->content(),
            $this->getJson('/api/exam/current')->content(),
            $this->getJson('/api/nope')->content(),
        ];

        $this->actingAs($participation->user);
        $this->postJson('/api/exam/start');
        $bodies[] = $this->postJson('/api/exam/answer', ['question_id' => 999999, 'selected_option' => 'A'])->content();

        foreach ($bodies as $body) {
            foreach (['select ', 'SQLSTATE', 'App\\Models', 'vendor\\laravel', 'correct_option', 'stack', '#0 '] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $body, "an error body leaked: {$forbidden}");
            }
        }
    }

    public function test_a_refusal_never_reveals_whose_question_was_named(): void
    {
        // A bank of 12 for papers of 3, so the two papers are genuinely
        // different subsets and a question exists on theirs but not on mine.
        // Nothing here depends on how the shuffle happened to fall.
        $competition = $this->makeCompetition(['question_count' => 3]);
        $this->makeQuestions($competition, 12);

        $mine = $this->makeContestant($competition);
        $theirs = $this->makeContestant($competition);

        $this->actingAs($theirs->user)->postJson('/api/exam/start')->assertOk();
        $this->actingAs($mine->user)->postJson('/api/exam/start')->assertOk();

        $myPaper = $mine->refresh()->order();

        $onlyTheirs = collect($theirs->refresh()->order())
            ->reject(fn (int $id) => in_array($id, $myPaper, true))
            ->first();

        $this->assertNotNull($onlyTheirs, 'the two papers must differ for this test to mean anything');

        $fabricated = $this->postJson('/api/exam/answer', ['question_id' => 555555, 'selected_option' => 'A']);
        $someoneElses = $this->postJson('/api/exam/answer', ['question_id' => $onlyTheirs, 'selected_option' => 'A']);

        // Byte-identical refusals. Anything else would turn this endpoint into
        // an oracle for probing other contestants' papers.
        $this->assertSame($fabricated->status(), $someoneElses->status());
        $this->assertSame($fabricated->json('reason'), $someoneElses->json('reason'));
        $this->assertSame($fabricated->json('message'), $someoneElses->json('message'));
    }
}
