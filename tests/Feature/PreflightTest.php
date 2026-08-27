<?php

namespace Tests\Feature;

use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Services\Competition\PreflightCheck;
use App\Services\Competition\PreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * The competition-day check: PASS, WARNING and FAIL, and the promise that it
 * never writes anything.
 */
class PreflightTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private function preflight(): PreflightService
    {
        return app(PreflightService::class);
    }

    /**
     * A competition in the exact shape Phase 1 specifies.
     *
     * The environment checks read config at runtime, so a production-like
     * configuration is simulated here — otherwise APP_ENV=testing would warn and
     * a genuine PASS could never be observed.
     */
    private function healthyCompetition(): CompetitionSettings
    {
        config(['app.env' => 'production', 'app.debug' => false]);

        $competition = $this->makeCompetition([
            'question_count' => PreflightService::EXPECTED_QUESTIONS,
            'seconds_per_question' => 40,
        ]);

        $this->makeQuestions($competition, PreflightService::EXPECTED_QUESTIONS);

        for ($i = 0; $i < 3; $i++) {
            $this->makeContestant($competition);
        }

        return $competition;
    }

    /** @param  list<PreflightCheck>  $checks */
    private function detail(array $checks, string $name): ?string
    {
        foreach ($checks as $check) {
            if ($check->name === $name) {
                return $check->status.': '.$check->detail;
            }
        }

        return null;
    }

    // ── PASS ────────────────────────────────────────────────────────────────

    public function test_a_correctly_prepared_competition_passes(): void
    {
        $competition = $this->healthyCompetition();

        $report = $this->preflight()->run($competition);

        $this->assertSame([], $report->failures(), 'unexpected blockers: '.json_encode(array_map(
            fn (PreflightCheck $c) => "{$c->name}: {$c->detail}", $report->failures(),
        )));
        $this->assertSame([], $report->warnings(), 'unexpected warnings: '.json_encode(array_map(
            fn (PreflightCheck $c) => "{$c->name}: {$c->detail}", $report->warnings(),
        )));
        $this->assertSame(PreflightCheck::PASS, $report->verdict());
        $this->assertTrue($report->passed());
    }

    public function test_the_command_exits_zero_on_a_healthy_competition(): void
    {
        $this->healthyCompetition();

        $this->artisan('madad:preflight')
            ->expectsOutputToContain('VERDICT: PASS')
            ->assertExitCode(0);
    }

    // ── WARNING ─────────────────────────────────────────────────────────────

    public function test_undelivered_credentials_are_a_warning_and_never_a_blocker(): void
    {
        $competition = $this->healthyCompetition();

        // A stated non-rule: failed delivery emails must not block launch.
        $this->makeContestant($competition, [
            // Attempted and not delivered IS `failed`.
            'credentials_sent_at' => null,
            'email_attempts' => 2,
            'email_last_error' => 'SMTP 550 recipient rejected',
        ]);
        $this->makeUnprovisionedContestant($competition);

        $report = $this->preflight()->run($competition);

        $this->assertSame([], $report->failures());
        $this->assertSame(PreflightCheck::WARNING, $report->verdict());
        $this->assertTrue($report->passed(), 'warnings must not fail the report');

        $names = array_map(fn (PreflightCheck $c) => $c->name, $report->warnings());
        $this->assertContains('credential delivery', $names);
        $this->assertContains('unprovisioned', $names);
    }

    public function test_the_command_exits_zero_on_warnings_but_non_zero_under_strict(): void
    {
        $competition = $this->healthyCompetition();
        $this->makeUnprovisionedContestant($competition);

        $this->artisan('madad:preflight')
            ->expectsOutputToContain('VERDICT: WARNING')
            ->assertExitCode(0);

        $this->artisan('madad:preflight --strict')->assertExitCode(1);
    }

    /**
     * The old shape of this test asked whether a SECOND competition was a
     * warning. Under the singleton it is not a warning, it is impossible: the
     * database refuses the row. That is a stronger guarantee, so this now
     * proves the refusal rather than the warning.
     */
    public function test_a_second_settings_row_cannot_exist_at_all(): void
    {
        $competition = $this->healthyCompetition();

        try {
            DB::table('competition_settings')->insert([
                'id' => 2,
                'name' => 'A second competition',
                'status' => 'draft',
                'show_result' => false,
                'question_count' => 75,
                'seconds_per_question' => 40,
                'exam_duration_minutes' => 60,
            ]);
            $this->fail('the database accepted a second settings row');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('chk_competition_settings_singleton', $e->getMessage());
        }

        $this->assertSame(1, DB::table('competition_settings')->count());
        $this->assertSame([], $this->preflight()->run($competition)->failures());
    }

    // ── FAIL ────────────────────────────────────────────────────────────────

    public function test_a_short_question_bank_is_a_blocker(): void
    {
        config(['app.env' => 'production', 'app.debug' => false]);

        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->makeQuestions($competition, 40);
        $this->makeContestant($competition);

        $report = $this->preflight()->run($competition);

        $this->assertSame(PreflightCheck::FAIL, $report->verdict());
        $this->assertFalse($report->passed());
        $this->assertStringContainsString('only 40 questions', $this->detail($report->failures(), 'bank size'));
    }

    public function test_a_question_missing_an_option_is_a_blocker(): void
    {
        $competition = $this->healthyCompetition();

        DB::table('competition_questions')
            ->where('question_number', 3)
            ->update(['option_c' => '']);

        $report = $this->preflight()->run($competition);

        $this->assertSame(PreflightCheck::FAIL, $report->verdict());
        $this->assertStringContainsString('1 questions have a missing or empty option', $this->detail($report->failures(), 'options A/B/C/D'));
    }

    public function test_a_zero_second_timer_is_a_blocker(): void
    {
        $competition = $this->healthyCompetition();
        DB::table('competition_settings')->where('id', 1)->update(['seconds_per_question' => 0]);

        $report = $this->preflight()->run($competition->fresh());

        $this->assertSame(PreflightCheck::FAIL, $report->verdict());
        $this->assertNotNull($this->detail($report->failures(), 'seconds_per_question'));
    }

    public function test_an_impossible_terminal_state_is_a_blocker(): void
    {
        $competition = $this->healthyCompetition();
        $participation = $this->makeContestant($competition);

        $this->actingAs($participation->user)->postJson('/api/exam/start')->assertOk();

        // An answer recorded at a position the contestant has not reached — a
        // state the engine can never produce.
        $participation->refresh()->forceFill([
            'answers' => 'A'.substr($participation->answers, 1),
            'current_question' => 0,
        ])->save();

        $report = $this->preflight()->run($competition);

        $this->assertSame(PreflightCheck::FAIL, $report->verdict());
        $this->assertStringContainsString(
            'contestants have answers recorded beyond their current position',
            $this->detail($report->failures(), 'answers ahead of position'),
        );
    }

    public function test_a_completed_total_that_disagrees_with_its_rows_is_a_blocker(): void
    {
        $competition = $this->healthyCompetition();
        $participation = $this->makeContestant($competition);
        $this->giveOrder($participation, $competition);

        $participation->forceFill([
            'exam_status' => CompetitionUser::EXAM_COMPLETED,
            'completed_at' => now(),
            'current_question' => $competition->question_count,
            'correct_answers' => 42,     // nothing in `answers` supports this
            'answered_questions' => 42,
        ])->save();

        $report = $this->preflight()->run($competition);

        $this->assertSame(PreflightCheck::FAIL, $report->verdict());
        $this->assertNotNull($this->detail($report->failures(), 'completed aggregates'));
    }

    public function test_an_orphan_account_link_is_a_blocker(): void
    {
        $competition = $this->healthyCompetition();
        $participation = $this->makeContestant($competition);

        // Point at a user row that does not exist. (The foreign key would
        // normally prevent this; the check exists because a restore, a manual
        // fix or a disabled key check can still produce it.)
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('competition_users')->where('id', $participation->id)->update(['user_id' => 987654]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $report = $this->preflight()->run($competition);

        $this->assertSame(PreflightCheck::FAIL, $report->verdict());
        $this->assertStringContainsString('1 participations point at a user row', $this->detail($report->failures(), 'orphan account links'));
    }

    public function test_no_settings_row_at_all_is_a_blocker(): void
    {
        // The migration seeds the singleton, so removing it is the only way to
        // reach this state — and an operator who has done that must be told.
        DB::table('competition_settings')->delete();

        $report = $this->preflight()->run();

        $this->assertSame(PreflightCheck::FAIL, $report->verdict());
        $this->assertStringContainsString(
            'no competition_settings row exists',
            $this->detail($report->failures(), 'settings'),
        );
    }

    public function test_the_command_exits_non_zero_on_a_blocker(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->makeQuestions($competition, 5);
        $this->makeContestant($competition);

        $this->artisan('madad:preflight')
            ->expectsOutputToContain('VERDICT: FAIL')
            ->assertExitCode(1);
    }

    // ── the read-only promise ───────────────────────────────────────────────

    public function test_preflight_changes_nothing(): void
    {
        $competition = $this->healthyCompetition();
        $participation = $this->makeContestant($competition);
        $this->actingAs($participation->user)->postJson('/api/exam/start')->assertOk();

        $before = $this->fingerprint();

        $this->preflight()->run($competition);
        $this->artisan('madad:preflight')->run();

        $this->assertSame($before, $this->fingerprint(), 'preflight must not modify any competition data');
    }

    /** @return array<string, mixed> */
    private function fingerprint(): array
    {
        $tables = ['competition_settings', 'competition_questions', 'competition_users', 'users'];
        $fingerprint = [];

        foreach ($tables as $table) {
            $fingerprint[$table] = md5(DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $fingerprint;
    }
}
