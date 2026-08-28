<?php

namespace Tests\Feature;

use App\Mail\ContestantCredentials;
use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Services\Competition\CredentialGateway;
use App\Services\Competition\LogCredentialGateway;
use App\Services\Competition\MailCredentialGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * The message that carries a contestant's password out of the system.
 *
 * It is the only place a plaintext credential leaves the process, which makes
 * it the only place it could be spilled. Most of what follows is about where
 * the password must NOT end up: not in a log, not in the jobs table, not in a
 * database column - and it must reach exactly one address.
 *
 * The rest is about failure. Provisioning walks a roster of a thousand, and a
 * gateway that throws would abandon everyone after the row that broke.
 */
class CredentialEmailTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private const PASSWORD = 'Kq7-Zm42-Tr9x';

    private function gateway(): MailCredentialGateway
    {
        return new MailCredentialGateway;
    }

    // ── Which gateway is in use ─────────────────────────────────────────────

    public function test_a_log_mailer_keeps_the_development_gateway(): void
    {
        foreach (['log', 'array'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->app->forgetInstance(CredentialGateway::class);

            $this->assertInstanceOf(
                LogCredentialGateway::class,
                $this->app->make(CredentialGateway::class),
                "MAIL_MAILER={$mailer} must not send real mail",
            );
        }
    }

    public function test_a_real_mailer_switches_to_sending_without_a_code_change(): void
    {
        // The whole switch: one line in .env. If this ever needs a second flag,
        // the two can disagree and one of them will be wrong on the day.
        config(['mail.default' => 'smtp']);
        $this->app->forgetInstance(CredentialGateway::class);

        $this->assertInstanceOf(MailCredentialGateway::class, $this->app->make(CredentialGateway::class));
    }

    public function test_a_missing_mailer_setting_does_not_silently_send_nothing(): void
    {
        // Unset should fall back to the gateway that TELLS you it did nothing,
        // rather than the one that reports success and sends nowhere.
        config(['mail.default' => null]);
        $this->app->forgetInstance(CredentialGateway::class);

        $this->assertInstanceOf(LogCredentialGateway::class, $this->app->make(CredentialGateway::class));
    }

    // ── The message ─────────────────────────────────────────────────────────

    public function test_the_credentials_reach_exactly_one_address(): void
    {
        $this->makeCompetition();
        Mail::fake();

        $result = $this->gateway()->send('sara@madad.test', 'سارة', self::PASSWORD);

        $this->assertTrue($result->delivered);

        Mail::assertSent(ContestantCredentials::class, function (ContestantCredentials $mail) {
            $this->assertCount(1, $mail->to, 'the credentials went to more than one address');

            return $mail->hasTo('sara@madad.test')
                && $mail->cc === []
                && $mail->bcc === [];
        });
    }

    public function test_the_message_carries_the_credentials_and_the_way_in(): void
    {
        $competition = $this->makeCompetition(['name' => 'مسابقة مداد', 'question_count' => 75]);
        config(['app.url' => 'https://madad.example']);

        $rendered = (new ContestantCredentials('سارة', self::PASSWORD, 'sara@madad.test', $competition))->render();

        foreach (['سارة', self::PASSWORD, 'sara@madad.test', 'https://madad.example', 'مسابقة مداد'] as $expected) {
            $this->assertStringContainsString($expected, $rendered, "the message is missing: {$expected}");
        }
    }

    public function test_the_message_states_the_rules_a_contestant_is_about_to_meet(): void
    {
        // A contestant who does not know the timer cannot be paused finds out
        // by losing a question. Telling them here costs nothing.
        $competition = $this->makeCompetition(['question_count' => 75, 'seconds_per_question' => 40]);

        $rendered = (new ContestantCredentials('سارة', self::PASSWORD, 'sara@madad.test', $competition))->render();

        $this->assertStringContainsString('75', $rendered);
        $this->assertStringContainsString('40', $rendered);
        $this->assertStringContainsString('انقطاع الاتّصال لا يوقف المؤقّت', $rendered);
    }

    public function test_the_message_reads_right_to_left(): void
    {
        $competition = $this->makeCompetition();

        $rendered = (new ContestantCredentials('سارة', self::PASSWORD, 'sara@madad.test', $competition))->render();

        $this->assertStringContainsString('dir="rtl"', $rendered);
        $this->assertStringContainsString('lang="ar"', $rendered);
    }

    public function test_the_message_survives_a_competition_with_no_dates_set(): void
    {
        // starts_at and ends_at are null until an operator sets them, and a
        // roster may well be provisioned before that happens.
        $competition = $this->makeCompetition(['starts_at' => null, 'ends_at' => null]);

        $rendered = (new ContestantCredentials('سارة', self::PASSWORD, 'sara@madad.test', $competition))->render();

        $this->assertStringContainsString(self::PASSWORD, $rendered);
    }

    // ── Where the password must not go ──────────────────────────────────────

    public function test_no_gateway_writes_the_password_to_the_log(): void
    {
        $this->makeCompetition();
        Mail::fake();

        $written = [];

        Log::listen(function ($message) use (&$written): void {
            $written[] = $message->message.' '.json_encode($message->context, JSON_UNESCAPED_UNICODE);
        });

        // Both implementations, because the leak would be in whichever one is
        // bound on the day, and the development gateway is the one that logs.
        (new LogCredentialGateway)->send('sara@madad.test', 'سارة', self::PASSWORD);
        $this->gateway()->send('sara@madad.test', 'سارة', self::PASSWORD);

        // Without this the loop below can pass by logging nothing at all, which
        // would make the test agree with a gateway that had been silenced.
        $this->assertNotEmpty($written, 'nothing was logged, so this test proved nothing');

        foreach ($written as $line) {
            $this->assertStringNotContainsString(self::PASSWORD, $line, 'a password reached the log');
        }
    }

    public function test_the_message_is_sent_rather_than_queued(): void
    {
        // Queueing serialises the mailable - password included - into the jobs
        // table, which is a password at rest in the database.
        $this->makeCompetition();
        Mail::fake();

        $this->gateway()->send('sara@madad.test', 'سارة', self::PASSWORD);

        Mail::assertSent(ContestantCredentials::class);
        Mail::assertNotQueued(ContestantCredentials::class);
    }

    public function test_delivering_credentials_stores_no_trace_of_the_password(): void
    {
        $competition = $this->makeCompetition();
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $this->app->forgetInstance(CredentialGateway::class);

        $participation = $this->makeUnprovisionedContestant($competition);

        $this->artisan('madad:provision', ['--limit' => 1])->assertExitCode(0);

        $participation->refresh();

        $this->assertNotNull($participation->credentials_sent_at, 'the delivery was not recorded');

        // Every column on the row, and the user row behind it.
        foreach (array_merge($participation->getAttributes(), $participation->user?->getAttributes() ?? []) as $column => $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString(
                    self::PASSWORD,
                    $value,
                    "a plaintext password was stored in {$column}",
                );
            }
        }
    }

    // ── Failure ─────────────────────────────────────────────────────────────

    public function test_a_gateway_failure_is_reported_and_not_thrown(): void
    {
        $this->makeCompetition();

        // A roster of a thousand: an exception escaping here would abandon
        // every contestant after the address that broke.
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('Connection refused'));

        $result = $this->gateway()->send('sara@madad.test', 'سارة', self::PASSWORD);

        $this->assertFalse($result->delivered);
        $this->assertStringContainsString('Connection refused', (string) $result->error);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $result->error, 'the password reached an error string');
    }

    public function test_a_failed_delivery_is_recorded_against_the_contestant_and_can_be_retried(): void
    {
        $competition = $this->makeCompetition();
        config(['mail.default' => 'smtp']);
        $this->app->forgetInstance(CredentialGateway::class);

        $participation = $this->makeUnprovisionedContestant($competition);

        Mail::shouldReceive('to->send')->once()->andThrow(new \RuntimeException('Mailbox unavailable'));

        $this->artisan('madad:provision', ['--limit' => 1])->assertExitCode(0);

        $participation->refresh();

        $this->assertNull($participation->credentials_sent_at);
        $this->assertSame(1, $participation->email_attempts);
        $this->assertStringContainsString('Mailbox unavailable', (string) $participation->email_last_error);
        $this->assertSame(CompetitionUser::EMAIL_FAILED, $participation->emailStatus());
    }

    public function test_the_subject_names_the_competition(): void
    {
        $competition = $this->makeCompetition(['name' => 'مسابقة مداد']);

        $envelope = (new ContestantCredentials('سارة', self::PASSWORD, 'sara@madad.test', $competition))->envelope();

        $this->assertStringContainsString('مسابقة مداد', $envelope->subject);
    }

    public function test_the_message_still_builds_with_no_competition_configured(): void
    {
        CompetitionSettings::query()->delete();

        $mail = new ContestantCredentials('سارة', self::PASSWORD, 'sara@madad.test', null);

        $this->assertStringContainsString(self::PASSWORD, $mail->render());
        $this->assertNotEmpty($mail->envelope()->subject);
    }
}
