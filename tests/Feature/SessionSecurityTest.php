<?php

namespace Tests\Feature;

use App\Models\CompetitionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * Session and authorisation behaviour for competition use.
 *
 * The properties asserted here are the ones an exam portal actually depends on:
 * a session id that cannot be fixed before login, a logout that really ends the
 * session, a login endpoint that cannot be used to discover who is registered,
 * and authorisation that is structural rather than advisory.
 */
class SessionSecurityTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private const PASSWORD = 'contestant-password';

    private function contestant(): CompetitionUser
    {
        $competition = $this->makeCompetition(['question_count' => 3]);
        $this->makeQuestions($competition, 3);

        $participation = $this->makeContestant($competition);
        $participation->user->forceFill(['password' => Hash::make(self::PASSWORD)])->save();

        return $participation;
    }

    public function test_logging_in_regenerates_the_session_id(): void
    {
        $participation = $this->contestant();

        // Touch the app once so a pre-login session exists to be fixed.
        $this->getJson('/api/competition/status')->assertOk();
        $before = session()->getId();

        $this->postJson('/api/login', [
            'email' => $participation->contestant_email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertNotSame($before, session()->getId(), 'the pre-login session id must not survive login');
        $this->assertAuthenticatedAs($participation->user);
    }

    public function test_logging_out_ends_the_session_and_rotates_the_csrf_token(): void
    {
        $participation = $this->contestant();

        $this->actingAs($participation->user)->getJson('/api/exam/current');
        $tokenBefore = session()->token();
        $idBefore = session()->getId();

        $this->postJson('/api/logout')->assertOk();

        $this->assertGuest();
        $this->assertNotSame($idBefore, session()->getId(), 'the session must be invalidated, not merely logged out');
        $this->assertNotSame($tokenBefore, session()->token(), 'the CSRF token must be rotated on logout');

        $this->getJson('/api/exam/current')->assertUnauthorized();
    }

    public function test_the_login_endpoint_cannot_be_used_to_discover_who_is_registered(): void
    {
        $participation = $this->contestant();

        $known = $this->postJson('/api/login', [
            'email' => $participation->contestant_email,
            'password' => 'definitely-wrong',
        ]);

        $unknown = $this->postJson('/api/login', [
            'email' => 'never-heard-of-them@madad.test',
            'password' => 'definitely-wrong',
        ]);

        // Identical status, identical code, identical body.
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('reason'), $unknown->json('reason'));
        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame($known->json('errors'), $unknown->json('errors'));
    }

    public function test_an_authenticated_contestant_only_ever_reaches_their_own_participation(): void
    {
        $mine = $this->contestant();
        $competition = $mine->competition;
        $theirs = $this->makeContestant($competition);

        $this->actingAs($theirs->user)->postJson('/api/exam/start')->assertOk();
        $this->actingAs($mine->user)->postJson('/api/exam/start')->assertOk();

        // There is no request parameter that names a participation, so the only
        // available proof is that the result belongs to the caller.
        $this->actingAs($theirs->user);
        $theirResult = $this->getJson('/api/exam/result')->assertOk();

        $this->actingAs($mine->user);
        $myResult = $this->getJson('/api/exam/result')->assertOk();

        foreach ([$theirResult, $myResult] as $response) {
            $body = $response->content();
            $this->assertStringNotContainsString('competition_user_id', $body);
            $this->assertStringNotContainsString('user_id', $body);
        }

        $this->assertSame(CompetitionUser::EXAM_IN_PROGRESS, $mine->fresh()->exam_status);
        $this->assertSame(CompetitionUser::EXAM_IN_PROGRESS, $theirs->fresh()->exam_status);
    }

    public function test_the_ownership_policy_refuses_another_contestants_row(): void
    {
        $mine = $this->contestant();
        $theirs = $this->makeContestant($mine->competition);

        // The exam flow never accepts a participation id, so this policy is not
        // what keeps it safe — but it is the explicit statement of the rule for
        // any future code path that does receive one from outside.
        $this->assertTrue(Gate::forUser($mine->user)->allows('view', $mine));
        $this->assertFalse(Gate::forUser($mine->user)->allows('view', $theirs));
        $this->assertFalse(Gate::forUser($mine->user)->allows('answer', $theirs));
    }

    public function test_a_completed_exam_is_not_answerable_under_the_policy(): void
    {
        $participation = $this->contestant();
        $participation->forceFill(['exam_status' => CompetitionUser::EXAM_COMPLETED])->save();

        $this->assertTrue(Gate::forUser($participation->user)->allows('view', $participation->fresh()));
        $this->assertFalse(Gate::forUser($participation->user)->allows('answer', $participation->fresh()));
    }

    public function test_secure_cookie_settings_are_configurable_rather_than_hard_coded(): void
    {
        // Nothing is deployed here. What matters for Phase 1 is that the
        // production hardening is a configuration change and not a code change;
        // the exact values to set are listed in docs/OPERATIONS.md.
        foreach (['session.secure', 'session.http_only', 'session.same_site', 'session.encrypt', 'session.lifetime'] as $key) {
            $this->assertTrue(config()->has($key), "{$key} must be configurable");
        }

        config(['session.secure' => true, 'session.same_site' => 'strict', 'session.encrypt' => true]);

        $this->assertTrue(config('session.secure'));
        $this->assertSame('strict', config('session.same_site'));
        $this->assertTrue(config('session.encrypt'));

        // HttpOnly must not be off by default — a readable session cookie is a
        // stealable one.
        $this->assertTrue((bool) config('session.http_only'));
    }

    public function test_the_login_route_is_closed_to_an_already_authenticated_contestant(): void
    {
        $participation = $this->contestant();

        // The `guest` middleware: a logged-in session cannot re-authenticate as
        // somebody else without logging out first.
        $response = $this->actingAs($participation->user)->postJson('/api/login', [
            'email' => $participation->contestant_email,
            'password' => self::PASSWORD,
        ]);

        $this->assertNotSame(200, $response->status());
    }

    public function test_the_answer_endpoint_is_rate_limited(): void
    {
        $participation = $this->contestant();
        $this->actingAs($participation->user);
        $this->postJson('/api/exam/start')->assertOk();

        $response = null;

        // The route limit is 120/minute; well above any honest contestant and
        // low enough to stop a scripted sweep of the option space.
        for ($i = 0; $i < 130; $i++) {
            $response = $this->postJson('/api/exam/answer', ['question_id' => 1, 'selected_option' => 'A']);
        }

        $this->assertSame(429, $response->status());
        $this->assertSame('too_many_attempts', $response->json('reason'));
    }
}
