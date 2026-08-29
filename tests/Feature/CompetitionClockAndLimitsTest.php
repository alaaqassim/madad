<?php

namespace Tests\Feature;

use App\Models\CompetitionSettings;
use App\Services\Competition\PreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * Two decisions taken about competition day, held here so they cannot drift.
 *
 * The clock: every hour anybody says out loud about this competition is a
 * Baghdad hour. MariaDB DATETIME carries no zone, so the application's timezone
 * IS the meaning of every stored timestamp - and an application on UTC would
 * open the portal three hours after the hour the operator wrote down, with
 * nothing anywhere saying so.
 *
 * The limits: reading is not free. Both read endpoints open transactions, and
 * the public one is keyed by address, where a whole hall is one address.
 */
class CompetitionClockAndLimitsTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    // ── The clock ───────────────────────────────────────────────────────────

    public function test_the_application_keeps_baghdad_time(): void
    {
        $this->assertSame('Asia/Baghdad', config('app.timezone'));
        $this->assertSame('Asia/Baghdad', now()->timezone->getName());
    }

    public function test_a_window_written_in_baghdad_hours_opens_at_those_hours(): void
    {
        // An operator writes "the portal opens at 09:00" and means 09:00 in
        // Baghdad. This is the whole point of the setting: what they wrote is
        // what the gate uses, with no arithmetic in anybody's head.
        $competition = $this->makeCompetition([
            'starts_at' => '2026-09-10 09:00:00',
            'ends_at' => '2026-09-10 11:00:00',
        ]);

        $this->travelTo('2026-09-10 08:59:00');
        $this->assertFalse($competition->withinWindow(), 'the portal was open a minute before the announced hour');

        $this->travelTo('2026-09-10 09:01:00');
        $this->assertTrue($competition->withinWindow(), 'the portal was shut a minute after the announced hour');

        $this->travelTo('2026-09-10 11:01:00');
        $this->assertFalse($competition->withinWindow(), 'the portal was still open past the announced close');
    }

    public function test_the_readiness_check_names_the_zone_it_is_reporting_in(): void
    {
        // A bare "09:00" in a report means whatever the reader assumes. Naming
        // the zone is what turns this check from one that hides a three hour
        // mistake into one that catches it.
        $competition = $this->makeCompetition([
            'starts_at' => '2026-09-10 09:00:00',
            'ends_at' => '2026-09-10 11:00:00',
        ]);

        $checks = app(PreflightService::class)->run($competition)->checks;

        $named = [];

        foreach ($checks as $check) {
            if (in_array($check->name, ['timezone', 'availability window'], true)) {
                $named[$check->name] = $check->detail;
            }
        }

        $this->assertArrayHasKey('timezone', $named, 'the readiness check does not report a timezone at all');

        foreach ($named as $name => $detail) {
            $this->assertStringContainsString(
                'Asia/Baghdad',
                $detail,
                "'{$name}' prints an hour without saying which zone it is in",
            );
        }
    }

    public function test_a_stored_timestamp_reads_back_as_the_hour_it_was_written(): void
    {
        $this->travelTo('2026-09-10 09:00:00');

        $competition = $this->makeCompetition();
        $contestant = $this->makeContestant($competition);

        $contestant->forceFill(['started_at' => now()])->save();

        // Straight out of the database, past the model, so this is about the
        // column and not about a cast.
        $raw = DB::table('competition_users')
            ->where('id', $contestant->id)
            ->value('started_at');

        $this->assertStringStartsWith('2026-09-10 09:00:00', $raw, 'the stored hour is not the hour that was written');
    }

    // ── The limits ──────────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: int}> */
    public static function limitedRoutes(): array
    {
        return [
            'the public status page' => ['api.competition.status', 120],
            'reading the current question' => ['api.exam.current', 60],
            'submitting an answer' => ['api.exam.answer', 120],
            'signing in' => ['api.login', 300],
        ];
    }

    /**
     * Read, not exercised: reaching any of these limits takes longer than the
     * minute they are measured over, so the counter would reset mid-test and
     * the run would measure the machine. That the middleware enforces its
     * number is Laravel's business. Ours is the number.
     *
     * @dataProvider limitedRoutes
     */
    public function test_every_endpoint_that_costs_something_carries_a_limit(string $route, int $expected): void
    {
        $throttle = collect(Route::getRoutes()->getByName($route)->gatherMiddleware())
            ->first(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

        $this->assertNotNull($throttle, "{$route} has no rate limit at all");

        [$perMinute] = explode(',', substr($throttle, strlen('throttle:')));

        $this->assertSame($expected, (int) $perMinute, "{$route} is no longer limited to {$expected} a minute");
    }

    public function test_an_ordinary_contestant_never_comes_near_the_read_limit(): void
    {
        // Sixty a minute against a contestant who needs it about once every
        // forty seconds. Twenty calls back to back is already far more than any
        // real client would make, and must still be served.
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $contestant = $this->makeContestant($competition);

        $this->actingAs($contestant->user);
        $this->postJson('/api/exam/start')->assertOk();

        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/exam/current')->assertOk();
        }
    }

    public function test_the_status_page_is_still_public(): void
    {
        // The limit must not have made it authenticated by accident: a
        // contestant has to be able to see a closed portal before logging in.
        $this->makeCompetition(['status' => CompetitionSettings::STATUS_CLOSED]);

        $this->getJson('/api/competition/status')
            ->assertOk()
            ->assertJsonPath('reason', 'competition_closed');
    }
}
