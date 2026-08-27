<?php

namespace Tests\Feature;

use App\Models\CompetitionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * Contestants who share one address must all get in.
 *
 * They will share one: a hall, a university network, a carrier NAT. To the
 * portal, hundreds of them are a single IP - and the busiest minute of the
 * whole competition is the one where all of them log in at once. A login limit
 * keyed by address therefore has to be a flood limit, not an attempt limit,
 * because it counts successful logins too.
 *
 * That distinction is invisible in the route definition and easy to undo by
 * lowering one number, so it is asserted here from both sides: a crowd gets in,
 * and a flood still does not.
 */
class SharedAddressLoginTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private const PASSWORD = 'contestant-password';

    /** @return list<CompetitionUser> */
    private function roster(int $count): array
    {
        $competition = $this->makeCompetition(['question_count' => 3]);
        $this->makeQuestions($competition, 3);

        $contestants = [];

        for ($i = 0; $i < $count; $i++) {
            $participation = $this->makeContestant($competition);
            $participation->user->forceFill(['password' => Hash::make(self::PASSWORD)])->save();

            $contestants[] = $participation;
        }

        return $contestants;
    }

    /**
     * Drops the previous contestant's session.
     *
     * Each contestant is a separate browser; the test client is one. Without
     * this the `guest` middleware would refuse the next login because somebody
     * is still signed in - a fact about the test client, not about the limit
     * being measured.
     */
    private function asANewBrowser(): void
    {
        Auth::logout();
        $this->flushSession();
    }

    public function test_a_hall_of_contestants_can_all_log_in_within_the_same_minute(): void
    {
        // Frozen so all forty logins land inside one minute however slow the
        // machine is. Without this the limit's window would reopen mid-run and
        // the test would pass for the wrong reason.
        $this->freezeTime();

        // Every request below comes from the same address: the test client
        // sends one, which is exactly the situation being tested.
        $contestants = $this->roster(40);

        foreach ($contestants as $position => $participation) {
            $this->asANewBrowser();

            $response = $this->postJson('/api/login', [
                'email' => $participation->contestant_email,
                'password' => self::PASSWORD,
            ]);

            $this->assertSame(
                200,
                $response->status(),
                sprintf(
                    'contestant %d of %d was refused with %d - the address limit is counting successful logins',
                    $position + 1,
                    count($contestants),
                    $response->status(),
                ),
            );
        }
    }

    public function test_the_address_limit_still_exists_and_is_sized_for_a_hall(): void
    {
        /*
         * The limit is read, not exercised.
         *
         * Reaching it means sending three hundred and one requests, which takes
         * longer than the minute the limit is measured over - so the counter
         * resets mid-test and the run measures the machine instead. That the
         * middleware enforces its number is Laravel's business and is already
         * tested there. Ours is the number, and the number is what regresses:
         * this fails the moment somebody puts it back near six.
         */
        $throttle = collect(Route::getRoutes()->getByName('api.login')->gatherMiddleware())
            ->first(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

        $this->assertNotNull($throttle, 'the login route lost its flood limit entirely');

        [$perMinute] = explode(',', substr($throttle, strlen('throttle:')));

        $this->assertGreaterThanOrEqual(
            200,
            (int) $perMinute,
            'the address limit counts successful logins, so a low number locks out everyone sharing an address',
        );
    }

    public function test_the_per_email_limiter_still_stops_guessing_one_password(): void
    {
        [$participation] = $this->roster(1);

        // Well inside the address limit, so anything that fires here is the
        // per-email limiter doing its job.
        $response = null;

        for ($i = 0; $i < 8; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => $participation->contestant_email,
                'password' => 'not-the-password',
            ]);
        }

        $this->assertSame(422, $response->status());
        $this->assertSame('too_many_attempts', $response->json('reason'));
    }

    public function test_a_successful_login_clears_the_contestants_failures(): void
    {
        [$participation] = $this->roster(1);

        // Four misses - one short of the lockout.
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login', [
                'email' => $participation->contestant_email,
                'password' => 'not-the-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => $participation->contestant_email,
            'password' => self::PASSWORD,
        ])->assertOk();

        // A contestant who mistypes their password four times, gets it right,
        // then loses their connection must be able to sign in again.
        $this->asANewBrowser();

        $this->postJson('/api/login', [
            'email' => $participation->contestant_email,
            'password' => self::PASSWORD,
        ])->assertOk();
    }
}
