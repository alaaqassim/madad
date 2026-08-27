<?php

namespace Tests\Feature;

use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Services\Competition\CompetitionExamService;
use App\Services\Competition\ResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * Nobody is left mid-exam once the exam is over.
 *
 * ─── THE DEFECT THIS FILE EXISTS FOR ────────────────────────────────────────
 * A contestant is settled by their own next request: the last answer finalises
 * on the spot, and a returning contestant whose time has run out is settled
 * before the gate. Neither fires for someone who closes the browser at question
 * 59 and never comes back — no request, no settlement.
 *
 * Every result surface filters on `exam_status = completed`, so such a
 * contestant disappears from their own result and from the Top 100 while their
 * answers sit intact in the row. Measured on the development fixtures that was
 * 100 contestants holding 3,500 answers, nine of whom had scored high enough
 * for a place — including one on 55, which would have been third.
 *
 * The confirmed rule: at the end of the exam there is no contestant in
 * progress, and each is measured against the end of their own exam.
 */
class SettleExamsTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private function exam(): CompetitionExamService
    {
        return app(CompetitionExamService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A contestant who began, answered a few, and walked away for good. */
    private function abandoned(CompetitionSettings $settings, int $answers): CompetitionUser
    {
        $contestant = $this->makeContestant($settings);
        $this->exam()->startOrResume($contestant->user, $settings);

        for ($i = 0; $i < $answers; $i++) {
            $this->travel(3)->seconds();
            $this->exam()->submitAnswer($contestant->refresh(), $settings, null, $this->correctOptionAt($contestant->refresh(), $i));
        }

        return $contestant->refresh();
    }

    public function test_a_contestant_who_never_returns_is_invisible_until_settled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        $settings = $this->makeCompetition(['question_count' => 10, 'exam_duration_minutes' => 60]);
        $this->makeQuestions($settings, 10);

        $contestant = $this->abandoned($settings, 6);

        $this->assertTrue($contestant->isInProgress());
        $this->assertSame(6, $contestant->correct_answers);

        // Long after their paper ran out, with nobody having touched them.
        Carbon::setTestNow(Carbon::parse('2026-09-05 23:00:00'));

        $this->assertCount(0, app(ResultService::class)->completed(), 'they should be missing before the sweep');

        $result = $this->exam()->settleAll($settings);

        $this->assertSame(1, $result['settled']);
        $this->assertSame(1, $result['expired']);
        $this->assertSame(0, $result['cut_short']);
        $this->assertSame(0, $result['remaining']);

        $contestant->refresh();

        $this->assertTrue($contestant->isCompleted());
        $this->assertSame(6, $contestant->correct_answers, 'the score they earned was not preserved');
        $this->assertSame(6, $contestant->answered_questions);
        $this->assertCount(1, app(ResultService::class)->completed(), 'they are still missing after the sweep');
    }

    public function test_the_settled_contestant_is_measured_against_the_end_of_their_own_exam(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        $settings = $this->makeCompetition(['question_count' => 10, 'exam_duration_minutes' => 60]);
        $this->makeQuestions($settings, 10);

        // Six answers, three seconds apart: the last lands at 09:00:18, leaving
        // four positions to spend a 40-second window each — so the paper is
        // over at 09:00:18 + 160s = 09:02:58.
        $contestant = $this->abandoned($settings, 6);

        Carbon::setTestNow(Carbon::parse('2026-09-06 12:00:00'));
        $this->exam()->settleAll($settings);

        $contestant->refresh();

        $this->assertSame(
            '2026-09-05T09:02:58+00:00',
            $contestant->completed_at->toIso8601String(),
            'the sweep recorded when it ran, not when the exam ended',
        );
        $this->assertSame(178, app(ResultService::class)->durationSeconds($contestant));
    }

    public function test_a_contestant_still_within_their_time_is_left_alone_by_default(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        $settings = $this->makeCompetition(['question_count' => 200, 'exam_duration_minutes' => 60]);
        $this->makeQuestions($settings, 200);

        $playing = $this->abandoned($settings, 2);

        // Ten minutes in: still playing, paper nowhere near spent.
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:10:00'));

        $result = $this->exam()->settleAll($settings);

        $this->assertSame(0, $result['settled'], 'a contestant still in their hour was settled');
        $this->assertSame(1, $result['remaining']);
        $this->assertTrue($playing->refresh()->isInProgress());
    }

    public function test_closing_the_competition_settles_even_those_still_within_their_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        $settings = $this->makeCompetition(['question_count' => 200, 'exam_duration_minutes' => 60]);
        $this->makeQuestions($settings, 200);

        $playing = $this->abandoned($settings, 2);

        Carbon::setTestNow(Carbon::parse('2026-09-05 09:10:00'));

        $result = $this->exam()->settleAll($settings, includeUnfinished: true);

        $this->assertSame(1, $result['settled']);
        $this->assertSame(0, $result['expired']);
        $this->assertSame(1, $result['cut_short']);
        $this->assertSame(0, $result['remaining']);

        $playing->refresh();

        $this->assertTrue($playing->isCompleted());
        // Cut short AT the closure, so that is the end of their exam.
        $this->assertSame('2026-09-05T09:10:00+00:00', $playing->completed_at->toIso8601String());
        $this->assertSame(2, $playing->correct_answers, 'their answers were discarded');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        $settings = $this->makeCompetition(['question_count' => 10, 'exam_duration_minutes' => 60]);
        $this->makeQuestions($settings, 10);

        $contestant = $this->abandoned($settings, 4);

        Carbon::setTestNow(Carbon::parse('2026-09-05 23:00:00'));

        $result = $this->exam()->settleAll($settings, dryRun: true);

        $this->assertSame(1, $result['settled'], 'the dry run must still report what it would do');
        $this->assertTrue($contestant->refresh()->isInProgress(), 'the dry run changed the row');
        $this->assertNull($contestant->completed_at);
    }

    public function test_settling_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        $settings = $this->makeCompetition(['question_count' => 10, 'exam_duration_minutes' => 60]);
        $this->makeQuestions($settings, 10);

        $contestant = $this->abandoned($settings, 5);

        Carbon::setTestNow(Carbon::parse('2026-09-05 23:00:00'));

        $this->exam()->settleAll($settings);
        $first = $contestant->refresh()->completed_at->toIso8601String();
        $score = $contestant->correct_answers;

        $second = $this->exam()->settleAll($settings);

        $this->assertSame(0, $second['settled'], 'a second sweep found work to do');
        $this->assertSame($first, $contestant->refresh()->completed_at->toIso8601String());
        $this->assertSame($score, $contestant->correct_answers);
    }

    public function test_a_settled_contestant_competes_on_equal_terms(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        $settings = $this->makeCompetition(['question_count' => 10, 'exam_duration_minutes' => 60]);
        $this->makeQuestions($settings, 10);

        // One walks away after six correct; one finishes the paper.
        $walked = $this->abandoned($settings, 6);

        $finished = $this->makeContestant($settings);
        $this->exam()->startOrResume($finished->user, $settings);

        for ($i = 0; $i < 10; $i++) {
            $this->travel(30)->seconds();
            $this->exam()->submitAnswer(
                $finished->refresh(),
                $settings,
                null,
                $i < 6 ? $this->correctOptionAt($finished->refresh(), $i) : $this->wrongOptionAt($finished->refresh(), $i),
            );
        }

        Carbon::setTestNow(Carbon::parse('2026-09-05 23:00:00'));
        $this->exam()->settleAll($settings);

        $order = app(ResultService::class)->completed()->pluck('id')->all();

        // Level on score at six. The one who walked away took 178 seconds; the
        // one who finished took 300. The tie-break puts the faster first, and a
        // settled contestant is subject to exactly the same rule.
        $this->assertSame(6, $walked->refresh()->correct_answers);
        $this->assertSame(6, $finished->refresh()->correct_answers);
        $this->assertSame([$walked->id, $finished->id], $order);
    }

    // ── the command ─────────────────────────────────────────────────────────

    public function test_the_command_reports_and_then_settles(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        $settings = $this->makeCompetition(['question_count' => 10, 'exam_duration_minutes' => 60]);
        $this->makeQuestions($settings, 10);

        $this->abandoned($settings, 3);
        $this->abandoned($settings, 5);

        Carbon::setTestNow(Carbon::parse('2026-09-05 23:00:00'));

        $this->artisan('madad:settle')
            ->expectsOutputToContain('to settle: 2')
            ->expectsOutputToContain('Settling is IRREVERSIBLE.')
            ->expectsConfirmation('Settle 2 contestant(s)?', 'yes')
            ->assertExitCode(0);

        $this->assertSame(0, CompetitionUser::query()->where('exam_status', CompetitionUser::EXAM_IN_PROGRESS)->count());
    }

    public function test_the_command_can_be_declined(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        $settings = $this->makeCompetition(['question_count' => 10, 'exam_duration_minutes' => 60]);
        $this->makeQuestions($settings, 10);

        $contestant = $this->abandoned($settings, 3);

        Carbon::setTestNow(Carbon::parse('2026-09-05 23:00:00'));

        $this->artisan('madad:settle')
            ->expectsConfirmation('Settle 1 contestant(s)?', 'no')
            ->expectsOutputToContain('REFUSED: nothing was settled.')
            ->assertExitCode(1);

        $this->assertTrue($contestant->refresh()->isInProgress());
    }

    public function test_the_command_dry_run_needs_no_confirmation_and_changes_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        $settings = $this->makeCompetition(['question_count' => 10, 'exam_duration_minutes' => 60]);
        $this->makeQuestions($settings, 10);

        $contestant = $this->abandoned($settings, 3);

        Carbon::setTestNow(Carbon::parse('2026-09-05 23:00:00'));

        $this->artisan('madad:settle', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: nothing was changed.')
            ->assertExitCode(0);

        $this->assertTrue($contestant->refresh()->isInProgress());
    }

    public function test_the_command_refuses_to_settle_non_interactively_without_force(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        $settings = $this->makeCompetition(['question_count' => 10, 'exam_duration_minutes' => 60]);
        $this->makeQuestions($settings, 10);

        $contestant = $this->abandoned($settings, 3);

        Carbon::setTestNow(Carbon::parse('2026-09-05 23:00:00'));

        $this->artisan('madad:settle', ['--force' => true])
            ->assertExitCode(0);

        $this->assertTrue($contestant->refresh()->isCompleted());
    }

    public function test_the_command_says_so_when_there_is_nothing_to_do(): void
    {
        $settings = $this->makeCompetition(['question_count' => 10]);
        $this->makeQuestions($settings, 10);

        $this->artisan('madad:settle', ['--dry-run' => true])
            ->expectsOutputToContain('Nothing to settle')
            ->assertExitCode(0);
    }
}
