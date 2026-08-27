<?php

namespace Tests\Feature;

use App\Models\CompetitionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * email_status is derived, and the derivation must hold in PHP and in SQL.
 *
 * The column was dropped because it only restated two columns that
 * CredentialDeliveryService writes together:
 *
 *     credentials_sent_at IS NOT NULL  ->  sent
 *     email_attempts > 0               ->  failed   (sent already excluded)
 *     otherwise                        ->  pending
 *
 * That leaves two implementations of one rule: the PHP accessor and
 * EMAIL_STATUS_SQL. They are compared here against every combination of the two
 * source columns, so neither can drift from the other or from the states the
 * delivery service actually produces.
 */
class EmailStatusDerivationTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    /** Every combination of the two columns the status is derived from. */
    public static function combinations(): array
    {
        return [
            'never attempted' => [null, 0, CompetitionUser::EMAIL_PENDING],
            'attempted once, no delivery' => [null, 1, CompetitionUser::EMAIL_FAILED],
            'attempted three times, no delivery' => [null, 3, CompetitionUser::EMAIL_FAILED],
            'delivered on the first attempt' => ['2026-09-05 09:00:00', 1, CompetitionUser::EMAIL_SENT],
            'delivered after failures' => ['2026-09-05 09:00:00', 4, CompetitionUser::EMAIL_SENT],
        ];
    }

    #[DataProvider('combinations')]
    public function test_php_and_sql_agree_on_every_combination(?string $sentAt, int $attempts, string $expected): void
    {
        $settings = $this->makeCompetition();
        $contestant = $this->makeContestant($settings);

        $contestant->forceFill([
            'credentials_sent_at' => $sentAt,
            'email_attempts' => $attempts,
        ])->save();

        $contestant->refresh();

        // 1. the PHP accessor
        $this->assertSame($expected, $contestant->email_status, 'the PHP accessor is wrong');
        $this->assertSame($expected, $contestant->emailStatus());

        // 2. the SQL used for reports
        $fromSql = DB::table('competition_users')
            ->where('id', $contestant->id)
            ->selectRaw(CompetitionUser::EMAIL_STATUS_SQL.' AS s')
            ->value('s');

        $this->assertSame($expected, $fromSql, 'EMAIL_STATUS_SQL disagrees with the PHP accessor');

        // 3. the query scopes
        $this->assertSame(
            1,
            CompetitionUser::query()->whereKey($contestant->id)->whereEmailStatus($expected)->count(),
            'whereEmailStatus did not match its own status',
        );

        foreach ([CompetitionUser::EMAIL_PENDING, CompetitionUser::EMAIL_SENT, CompetitionUser::EMAIL_FAILED] as $other) {
            if ($other === $expected) {
                continue;
            }

            $this->assertSame(
                0,
                CompetitionUser::query()->whereKey($contestant->id)->whereEmailStatus($other)->count(),
                "whereEmailStatus({$other}) matched a row that is {$expected}",
            );

            $this->assertSame(
                1,
                CompetitionUser::query()->whereKey($contestant->id)->whereEmailStatusNot($other)->count(),
                "whereEmailStatusNot({$other}) excluded a row that is {$expected}",
            );
        }
    }

    public function test_the_column_is_gone(): void
    {
        $this->assertFalse(
            Schema::hasColumn('competition_users', 'email_status'),
            'the column is back; the derivation is now a second source of truth',
        );
    }

    public function test_a_successful_delivery_produces_sent(): void
    {
        $settings = $this->makeCompetition();
        $contestant = $this->makeContestant($settings, [
            'credentials_sent_at' => null,
            'email_attempts' => 0,
        ]);

        $this->assertSame(CompetitionUser::EMAIL_PENDING, $contestant->email_status);

        // What CredentialDeliveryService writes on success.
        $contestant->forceFill([
            'email_attempts' => $contestant->email_attempts + 1,
            'credentials_sent_at' => now(),
            'email_last_error' => null,
        ])->save();

        $this->assertSame(CompetitionUser::EMAIL_SENT, $contestant->refresh()->email_status);
    }

    public function test_a_failed_delivery_produces_failed(): void
    {
        $settings = $this->makeCompetition();
        $contestant = $this->makeContestant($settings, [
            'credentials_sent_at' => null,
            'email_attempts' => 0,
        ]);

        // What CredentialDeliveryService writes on failure.
        $contestant->forceFill([
            'email_attempts' => $contestant->email_attempts + 1,
            'credentials_sent_at' => null,
            'email_last_error' => 'SMTP 550 recipient rejected',
        ])->save();

        $this->assertSame(CompetitionUser::EMAIL_FAILED, $contestant->refresh()->email_status);
    }

    public function test_a_retry_after_failure_flips_to_sent(): void
    {
        $settings = $this->makeCompetition();
        $contestant = $this->makeContestant($settings, [
            'credentials_sent_at' => null,
            'email_attempts' => 2,
            'email_last_error' => 'SMTP 421 try again later',
        ]);

        $this->assertSame(CompetitionUser::EMAIL_FAILED, $contestant->email_status);

        $contestant->forceFill([
            'email_attempts' => 3,
            'credentials_sent_at' => now(),
            'email_last_error' => null,
        ])->save();

        $this->assertSame(CompetitionUser::EMAIL_SENT, $contestant->refresh()->email_status);
    }

    public function test_grouping_by_the_derivation_counts_the_whole_field(): void
    {
        $settings = $this->makeCompetition();

        for ($i = 0; $i < 4; $i++) {
            $this->makeContestant($settings, ['credentials_sent_at' => now(), 'email_attempts' => 1]);
        }

        for ($i = 0; $i < 3; $i++) {
            $this->makeContestant($settings, ['credentials_sent_at' => null, 'email_attempts' => 2]);
        }

        for ($i = 0; $i < 2; $i++) {
            $this->makeContestant($settings, ['credentials_sent_at' => null, 'email_attempts' => 0]);
        }

        $counts = DB::table('competition_users')
            ->selectRaw(CompetitionUser::EMAIL_STATUS_SQL.' AS s, COUNT(*) c')
            ->groupByRaw(CompetitionUser::EMAIL_STATUS_SQL)
            ->pluck('c', 's')
            ->all();

        $this->assertSame(4, $counts[CompetitionUser::EMAIL_SENT] ?? 0);
        $this->assertSame(3, $counts[CompetitionUser::EMAIL_FAILED] ?? 0);
        $this->assertSame(2, $counts[CompetitionUser::EMAIL_PENDING] ?? 0);
        $this->assertSame(9, array_sum($counts), 'the three states must cover every row');
    }
}
