<?php

namespace Tests\Feature;

use App\Models\CompetitionUser;
use App\Models\User;
use App\Services\Competition\ContestantProvisioningService;
use App\Services\Competition\CredentialDeliveryService;
use App\Services\Competition\CredentialGateway;
use App\Services\Competition\GatewayResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

class ProvisioningTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    /** Captures the plaintext the gateway is handed, so tests can verify it works. */
    private function recordingGateway(bool $succeed = true): object
    {
        return new class($succeed) implements CredentialGateway
        {
            public array $sent = [];

            public function __construct(private bool $succeed) {}

            public function send(string $email, string $name, string $plaintextPassword): GatewayResult
            {
                $this->sent[] = ['email' => $email, 'password' => $plaintextPassword];

                return $this->succeed
                    ? GatewayResult::delivered()
                    : GatewayResult::failed('SMTP 550 recipient rejected');
            }
        };
    }

    public function test_it_creates_an_account_and_marks_the_credential_delivered(): void
    {
        $competition = $this->makeCompetition();
        $participation = $this->makeUnprovisionedContestant($competition);
        $gateway = $this->recordingGateway();
        $this->app->instance(CredentialGateway::class, $gateway);

        $this->assertTrue(app(CredentialDeliveryService::class)->deliver($participation));

        $participation->refresh();
        $this->assertSame(CompetitionUser::ACCOUNT_CREATED, $participation->account_status);
        $this->assertSame(CompetitionUser::EMAIL_SENT, $participation->email_status);
        $this->assertNotNull($participation->user_id);
        $this->assertNotNull($participation->credentials_generated_at);
        $this->assertNotNull($participation->credentials_sent_at);
        $this->assertNull($participation->email_last_error);
        $this->assertSame(1, $participation->email_attempts);
    }

    public function test_the_password_is_stored_only_as_a_hash_and_actually_works(): void
    {
        $competition = $this->makeCompetition();
        $participation = $this->makeUnprovisionedContestant($competition);
        $gateway = $this->recordingGateway();
        $this->app->instance(CredentialGateway::class, $gateway);

        app(CredentialDeliveryService::class)->deliver($participation);

        $plaintext = $gateway->sent[0]['password'];
        $user = User::query()->find($participation->fresh()->user_id);

        $this->assertStringStartsWith('$2y$', $user->password);
        $this->assertNotSame($plaintext, $user->password);
        $this->assertTrue(Hash::check($plaintext, $user->password));
    }

    public function test_the_plaintext_is_never_persisted_in_any_column(): void
    {
        $competition = $this->makeCompetition();
        $participation = $this->makeUnprovisionedContestant($competition);
        $gateway = $this->recordingGateway();
        $this->app->instance(CredentialGateway::class, $gateway);

        app(CredentialDeliveryService::class)->deliver($participation);
        $plaintext = $gateway->sent[0]['password'];

        // Sweep every text column of both tables for the credential.
        foreach (['competition_users', 'users'] as $table) {
            $columns = DB::getSchemaBuilder()->getColumnListing($table);

            foreach (DB::table($table)->get() as $record) {
                foreach ($columns as $column) {
                    $value = $record->{$column} ?? null;

                    if (is_string($value)) {
                        $this->assertStringNotContainsString($plaintext, $value, "{$table}.{$column} leaked the credential");
                    }
                }
            }
        }
    }

    public function test_rerunning_provisioning_does_not_create_a_second_user(): void
    {
        $competition = $this->makeCompetition();
        $participation = $this->makeUnprovisionedContestant($competition);
        $this->app->instance(CredentialGateway::class, $this->recordingGateway());
        $service = app(CredentialDeliveryService::class);

        $service->deliver($participation);
        $firstUserId = $participation->fresh()->user_id;

        $service->deliver($participation->fresh());

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($firstUserId, $participation->fresh()->user_id);
        $this->assertDatabaseCount('competition_users', 1);
    }

    public function test_a_failed_delivery_is_recorded_with_its_error(): void
    {
        $competition = $this->makeCompetition();
        $participation = $this->makeUnprovisionedContestant($competition);
        $this->app->instance(CredentialGateway::class, $this->recordingGateway(succeed: false));

        $this->assertFalse(app(CredentialDeliveryService::class)->deliver($participation));

        $participation->refresh();
        $this->assertSame(CompetitionUser::EMAIL_FAILED, $participation->email_status);
        $this->assertNull($participation->credentials_sent_at);
        $this->assertSame(1, $participation->email_attempts);
        $this->assertStringContainsString('550', $participation->email_last_error);
        // The account itself was still created — that step succeeded.
        $this->assertSame(CompetitionUser::ACCOUNT_CREATED, $participation->account_status);
    }

    public function test_retry_issues_a_new_password_and_replaces_the_hash(): void
    {
        $competition = $this->makeCompetition();
        $participation = $this->makeUnprovisionedContestant($competition);
        $failing = $this->recordingGateway(succeed: false);
        $this->app->instance(CredentialGateway::class, $failing);

        app(CredentialDeliveryService::class)->deliver($participation);
        $firstPassword = $failing->sent[0]['password'];
        $firstHash = User::query()->find($participation->fresh()->user_id)->password;

        $succeeding = $this->recordingGateway();
        $this->app->instance(CredentialGateway::class, $succeeding);
        $this->assertTrue(app(CredentialDeliveryService::class)->retry($participation->fresh()));

        $secondPassword = $succeeding->sent[0]['password'];
        $user = User::query()->find($participation->fresh()->user_id);

        $this->assertNotSame($firstPassword, $secondPassword, 'retry must issue a new credential, not replay the old one');
        $this->assertNotSame($firstHash, $user->password);
        $this->assertTrue(Hash::check($secondPassword, $user->password));
        $this->assertFalse(Hash::check($firstPassword, $user->password), 'the superseded password must stop working');

        $participation->refresh();
        $this->assertSame(CompetitionUser::EMAIL_SENT, $participation->email_status);
        $this->assertSame(2, $participation->email_attempts);
        $this->assertNull($participation->email_last_error);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_provisioning_adopts_an_existing_user_with_the_same_email(): void
    {
        $competition = $this->makeCompetition();
        $participation = $this->makeUnprovisionedContestant($competition);

        $existing = User::query()->create([
            'name' => 'موجود مسبقًا',
            'email' => $participation->contestant_email,
            'password' => Hash::make('whatever'),
        ]);

        app(ContestantProvisioningService::class)->provision($participation);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($existing->id, $participation->fresh()->user_id);
    }
}
