<?php

namespace Tests\Feature;

use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Models\User;
use App\Services\Competition\CredentialGateway;
use App\Services\Competition\GatewayResult;
use App\Services\Competition\PreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * The commands an operator runs on competition day.
 *
 * The safety properties being locked here are: status never changes without
 * being asked to, opening is refused when the competition is not ready, closing
 * requires an explicit confirmation because it ENDS the competition, and
 * provisioning can be rerun without creating a second account for anybody.
 */
class OperationalCommandsTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private function launchable(string $status = CompetitionSettings::STATUS_READY): CompetitionSettings
    {
        $competition = $this->makeCompetition([
            'status' => $status,
            'question_count' => PreflightService::EXPECTED_QUESTIONS,
            'seconds_per_question' => 40,
        ]);

        $this->makeQuestions($competition, PreflightService::EXPECTED_QUESTIONS);
        $this->makeContestant($competition);

        return $competition;
    }

    // ── madad:status — reading ──────────────────────────────────────────────

    public function test_reading_the_status_changes_nothing(): void
    {
        $competition = $this->launchable();
        $this->makeContestant($competition)->forceFill(['exam_status' => CompetitionUser::EXAM_IN_PROGRESS])->save();
        $this->makeContestant($competition)->forceFill(['exam_status' => CompetitionUser::EXAM_COMPLETED, 'completed_at' => now()])->save();

        $this->artisan('madad:status')
            ->expectsOutputToContain('ready')
            ->expectsOutputToContain('question_count')
            ->expectsOutputToContain('seconds_per_question')
            ->expectsOutputToContain('show_result')
            ->expectsOutputToContain('not_started')
            ->expectsOutputToContain('in_progress')
            ->expectsOutputToContain('completed')
            ->assertExitCode(0);

        // The whole point: no --set, no change.
        $this->assertSame(CompetitionSettings::STATUS_READY, $competition->fresh()->status);
    }

    // ── madad:status --set=open ─────────────────────────────────────────────

    public function test_opening_requires_confirmation_and_then_opens(): void
    {
        $competition = $this->launchable();

        $this->artisan('madad:status', ['--set' => 'open'])
            ->expectsConfirmation('Change the competition from ready to open?', 'yes')
            ->assertExitCode(0);

        $this->assertSame(CompetitionSettings::STATUS_OPEN, $competition->fresh()->status);
    }

    public function test_declining_the_confirmation_leaves_the_status_alone(): void
    {
        $competition = $this->launchable();

        $this->artisan('madad:status', ['--set' => 'open'])
            ->expectsConfirmation('Change the competition from ready to open?', 'no')
            ->expectsOutputToContain('Cancelled. Nothing was changed.')
            ->assertExitCode(1);

        $this->assertSame(CompetitionSettings::STATUS_READY, $competition->fresh()->status);
    }

    public function test_opening_is_refused_when_the_readiness_check_finds_a_blocker(): void
    {
        // A paper of 75 with only 5 questions in the bank: papers cannot be built.
        $competition = $this->makeCompetition(['status' => CompetitionSettings::STATUS_READY, 'question_count' => 75]);
        $this->makeQuestions($competition, 5);
        $this->makeContestant($competition);

        $this->artisan('madad:status', ['--set' => 'open', '--force' => true])
            ->expectsOutputToContain('BLOCKER')
            ->expectsOutputToContain('The competition was NOT opened.')
            ->assertExitCode(1);

        $this->assertSame(CompetitionSettings::STATUS_READY, $competition->fresh()->status, 'a failed readiness check must not open the portal');
    }

    public function test_opening_proceeds_despite_warnings(): void
    {
        $competition = $this->launchable();
        // Undelivered credentials are a warning, and no stated rule makes them
        // a launch blocker.
        $this->makeUnprovisionedContestant($competition);

        $this->artisan('madad:status', ['--set' => 'open', '--force' => true])
            ->expectsOutputToContain('WARNING')
            ->assertExitCode(0);

        $this->assertSame(CompetitionSettings::STATUS_OPEN, $competition->fresh()->status);
    }

    // ── madad:status --set=closed ───────────────────────────────────────────

    public function test_closing_states_that_it_ends_the_competition_and_names_who_is_cut_off(): void
    {
        $competition = $this->launchable(CompetitionSettings::STATUS_OPEN);
        $this->makeContestant($competition)->forceFill(['exam_status' => CompetitionUser::EXAM_IN_PROGRESS])->save();
        $this->makeContestant($competition)->forceFill(['exam_status' => CompetitionUser::EXAM_IN_PROGRESS])->save();

        $this->artisan('madad:status', ['--set' => 'closed'])
            ->expectsOutputToContain('CLOSING ENDS THE COMPETITION.')
            ->expectsOutputToContain('2 contestant(s) are mid-exam right now. They will be cut off, then SCORED AND')
            ->expectsConfirmation('Change the competition from open to closed?', 'yes')
            ->assertExitCode(0);

        $this->assertSame(CompetitionSettings::STATUS_CLOSED, $competition->fresh()->status);

        // The confirmed rule: nobody is left mid-exam once the competition has
        // ended. A contestant left in progress would lose the answers they had
        // already given, because every result surface filters on `completed`.
        $this->assertSame(
            0,
            CompetitionUser::query()->where('exam_status', CompetitionUser::EXAM_IN_PROGRESS)->count(),
            'closing left contestants stranded in progress',
        );
        $this->assertSame(2, CompetitionUser::query()->where('exam_status', CompetitionUser::EXAM_COMPLETED)->count());
    }

    public function test_closing_can_be_declined(): void
    {
        $competition = $this->launchable(CompetitionSettings::STATUS_OPEN);

        $this->artisan('madad:status', ['--set' => 'closed'])
            ->expectsConfirmation('Change the competition from open to closed?', 'no')
            ->assertExitCode(1);

        $this->assertSame(CompetitionSettings::STATUS_OPEN, $competition->fresh()->status);
    }

    public function test_closing_with_force_does_not_ask(): void
    {
        $competition = $this->launchable(CompetitionSettings::STATUS_OPEN);

        $this->artisan('madad:status', ['--set' => 'closed', '--force' => true])
            ->expectsOutputToContain('The competition has ended.')
            ->assertExitCode(0);

        $this->assertSame(CompetitionSettings::STATUS_CLOSED, $competition->fresh()->status);
    }

    // ── madad:status — safeguards ───────────────────────────────────────────

    public function test_an_unknown_status_is_rejected_without_changing_anything(): void
    {
        $competition = $this->launchable(CompetitionSettings::STATUS_OPEN);

        $this->artisan('madad:status', ['--set' => 'paused'])
            ->expectsOutputToContain('--set must be one of: draft, ready, open, closed')
            ->assertExitCode(2);

        $this->assertSame(CompetitionSettings::STATUS_OPEN, $competition->fresh()->status);
    }

    public function test_setting_the_status_it_already_has_is_a_no_op(): void
    {
        $competition = $this->launchable(CompetitionSettings::STATUS_OPEN);

        $this->artisan('madad:status', ['--set' => 'open'])
            ->expectsOutputToContain('status is already open; nothing to do.')
            ->assertExitCode(0);

        $this->assertSame(CompetitionSettings::STATUS_OPEN, $competition->fresh()->status);
    }

    /**
     * The commands used to take a competition id. Under the singleton there is
     * nothing to name, so passing one is a usage error rather than a lookup
     * that fails — which is the stronger guarantee: no shape of this command
     * can be pointed at the wrong competition.
     */
    public function test_the_status_command_takes_no_competition_argument(): void
    {
        $this->launchable();

        $this->expectException(\InvalidArgumentException::class);

        $this->artisan('madad:status', ['competition' => '999'])->run();
    }

    // ── madad:provision ─────────────────────────────────────────────────────

    private function bindGateway(bool $succeed = true): void
    {
        $this->app->instance(CredentialGateway::class, new class($succeed) implements CredentialGateway
        {
            public function __construct(private bool $succeed) {}

            public function send(string $email, string $name, string $plaintextPassword): GatewayResult
            {
                return $this->succeed
                    ? GatewayResult::delivered()
                    : GatewayResult::failed('SMTP 550 recipient rejected');
            }
        });
    }

    public function test_provisioning_reports_the_full_operational_picture(): void
    {
        $competition = $this->makeCompetition();

        for ($i = 0; $i < 3; $i++) {
            $this->makeUnprovisionedContestant($competition);
        }

        $this->bindGateway();

        $this->artisan('madad:provision')
            ->expectsOutputToContain('source participations (total)')
            ->expectsOutputToContain('accounts already created before this run')
            ->expectsOutputToContain('accounts newly created by this run')
            ->expectsOutputToContain('email delivered by this run')
            ->expectsOutputToContain('delivery retries attempted')
            ->expectsOutputToContain('delivery failures this run')
            ->expectsOutputToContain('skipped (already delivered or not selected)')
            ->assertExitCode(0);

        $this->assertSame(3, CompetitionUser::query()->where('account_status', CompetitionUser::ACCOUNT_CREATED)->count());
        $this->assertSame(3, CompetitionUser::query()->where('email_status', CompetitionUser::EMAIL_SENT)->count());
        $this->assertDatabaseCount('users', 3);
    }

    public function test_rerunning_provisioning_creates_no_second_account_and_resends_nothing(): void
    {
        $competition = $this->makeCompetition();

        for ($i = 0; $i < 3; $i++) {
            $this->makeUnprovisionedContestant($competition);
        }

        $this->bindGateway();
        $this->artisan('madad:provision')->assertExitCode(0);

        $userIds = CompetitionUser::query()->orderBy('id')->pluck('user_id')->all();
        $hashes = User::query()->orderBy('id')->pluck('password')->all();

        // The rerun.
        $this->artisan('madad:provision')->assertExitCode(0);

        $this->assertDatabaseCount('users', 3);
        $this->assertSame($userIds, CompetitionUser::query()->orderBy('id')->pluck('user_id')->all());
        // Already-delivered rows are not selected at all, so no password that a
        // contestant is already holding is invalidated by a rerun.
        $this->assertSame($hashes, User::query()->orderBy('id')->pluck('password')->all());
    }

    public function test_failed_deliveries_are_reported_and_only_retried_when_asked(): void
    {
        $competition = $this->makeCompetition();
        $this->makeUnprovisionedContestant($competition);

        $this->bindGateway(succeed: false);

        $this->artisan('madad:provision')
            ->expectsOutputToContain('delivery error(s)')
            ->expectsOutputToContain('SMTP 550')
            ->assertExitCode(0);

        $participation = CompetitionUser::query()->first();
        $this->assertSame(CompetitionUser::EMAIL_FAILED, $participation->email_status);
        $this->assertSame(1, $participation->email_attempts);

        // A plain rerun does not touch failed rows.
        $this->artisan('madad:provision')->assertExitCode(0);
        $this->assertSame(1, $participation->fresh()->email_attempts);

        // --retry-failed does, and counts it as a retry.
        $this->bindGateway();
        $this->artisan('madad:provision', ['--retry-failed' => true])
            ->expectsOutputToContain('delivery retries attempted')
            ->assertExitCode(0);

        $participation->refresh();
        $this->assertSame(CompetitionUser::EMAIL_SENT, $participation->email_status);
        $this->assertSame(2, $participation->email_attempts);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $competition = $this->makeCompetition();
        $this->makeUnprovisionedContestant($competition);
        $this->bindGateway();

        $this->artisan('madad:provision', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('would attempt: 1')
            ->assertExitCode(0);

        $this->assertDatabaseCount('users', 0);
        $this->assertSame(CompetitionUser::ACCOUNT_PENDING, CompetitionUser::query()->first()->account_status);
    }

    public function test_no_command_output_ever_contains_a_plaintext_credential(): void
    {
        $competition = $this->makeCompetition();
        $this->makeUnprovisionedContestant($competition);

        $sent = [];
        $this->app->instance(CredentialGateway::class, new class($sent) implements CredentialGateway
        {
            public array $captured = [];

            public function __construct(private array $unused) {}

            public function send(string $email, string $name, string $plaintextPassword): GatewayResult
            {
                $this->captured[] = $plaintextPassword;

                return GatewayResult::delivered();
            }
        });

        $this->artisan('madad:provision')->assertExitCode(0);

        $gateway = $this->app->make(CredentialGateway::class);
        $this->assertNotEmpty($gateway->captured);

        $output = Artisan::output();

        foreach ($gateway->captured as $password) {
            $this->assertStringNotContainsString($password, $output, 'a credential must never reach the console');
        }
    }
}
