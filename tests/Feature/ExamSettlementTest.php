<?php

namespace Tests\Feature;

use App\Exceptions\ExamException;
use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Services\Competition\CompetitionExamService;
use App\Services\Competition\ResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * Reaching effective_end SETTLES the exam — it does not merely refuse it.
 *
 * ─── THE DEFECT THIS FILE EXISTS FOR ────────────────────────────────────────
 * The confirmed rule is that `now >= effective_end` is terminal COMPLETED, and
 * effective_end is min(started_at + duration, settings.ends_at). There are two
 * ways to reach it, and only one of them settled the row:
 *
 *   personal deadline   reconcile() finalises — the gate still permits, so the
 *                       request gets far enough to do it
 *   window end          the gate refused FIRST, so reconcile() never ran and the
 *                       contestant stayed `in_progress` for ever
 *
 * That second case is not a cosmetic status. Every result surface filters on
 * `exam_status = completed`, so a contestant whose exam the WINDOW ended was
 * absent from their own result, from the Top 100 and from the CSV export —
 * with the answers they had submitted sitting intact in the database.
 *
 * Settling is deliberately NOT done for a manual `status = closed` before the
 * window: that is an operator switch, not the passage of time, and finalising
 * on it would be a business rule nobody approved.
 */
class ExamSettlementTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private function exam(): CompetitionExamService
    {
        return app(CompetitionExamService::class);
    }

    /**
     * Window 09:00 to 11:00, allowance 60 minutes, contestant begins 10:15.
     *
     * Worked example B, carried one step past the refusal: the exam must be
     * OVER at 11:00, not merely unreachable.
     *
     * @return array{0: CompetitionSettings, 1: CompetitionUser}
     */
    private function lateStarter(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:15:00'));

        $settings = $this->makeCompetition([
            'question_count' => 200,
            'exam_duration_minutes' => 60,
            'starts_at' => Carbon::parse('2026-09-05 09:00:00'),
            'ends_at' => Carbon::parse('2026-09-05 11:00:00'),
        ]);
        $this->makeQuestions($settings, 200);
        $contestant = $this->makeContestant($settings);

        $this->exam()->startOrResume($contestant->user, $settings);

        // One real answer, so there is something to lose.
        $this->exam()->submitAnswer(
            $contestant->refresh(),
            $settings,
            null,
            $this->correctOptionAt($contestant->refresh(), 0),
        );

        return [$settings, $contestant->refresh()];
    }

    public function test_the_window_end_completes_an_in_flight_exam(): void
    {
        [$settings, $contestant] = $this->lateStarter();

        $this->assertTrue($contestant->isInProgress());

        Carbon::setTestNow(Carbon::parse('2026-09-05 11:00:00'));

        try {
            $this->exam()->state($contestant->refresh(), $settings);
            $this->fail('the portal served a contestant after the window closed');
        } catch (ExamException) {
            // Refusing is correct. Refusing WITHOUT settling was the defect.
        }

        $contestant->refresh();

        $this->assertTrue($contestant->isCompleted(), 'the window ended the exam but did not complete it');
        $this->assertNotNull($contestant->completed_at);
        $this->assertSame(1, $contestant->answered_questions, 'a submitted answer was lost');
        $this->assertSame(1, $contestant->correct_answers);

        Carbon::setTestNow();
    }

    public function test_a_contestant_ended_by_the_window_still_appears_in_the_results(): void
    {
        [$settings, $contestant] = $this->lateStarter();

        Carbon::setTestNow(Carbon::parse('2026-09-05 11:00:00'));

        try {
            $this->exam()->state($contestant->refresh(), $settings);
        } catch (ExamException) {
            // expected
        }

        $top = app(ResultService::class)->topN($settings, 100);

        $this->assertSame(1, $top['total_completed'], 'the contestant vanished from the results');
        $this->assertSame(
            $contestant->id,
            $top['rows'][0]['competition_user_id'] ?? null,
            'the Top 100 lost a contestant whose exam the window ended',
        );

        Carbon::setTestNow();
    }

    public function test_the_result_endpoint_settles_and_then_reports(): void
    {
        [$settings, $contestant] = $this->lateStarter();
        $settings->forceFill(['show_result' => true])->save();

        Carbon::setTestNow(Carbon::parse('2026-09-05 11:00:00'));

        // The result surface does not go through the gate, so it is the one
        // place a cut-off contestant can still reach. It must settle first.
        $result = $this->exam()->result($contestant->refresh(), $settings);

        $this->assertSame(CompetitionUser::EXAM_COMPLETED, $result['exam_status']);
        $this->assertSame(1, $result['correct_answers']);
        $this->assertTrue($contestant->refresh()->isCompleted());

        Carbon::setTestNow();
    }

    public function test_the_personal_deadline_settles_the_same_way(): void
    {
        // The half that already worked, asserted here so the two paths are
        // proven to agree rather than assumed to.
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        $settings = $this->makeCompetition([
            'question_count' => 200,
            'exam_duration_minutes' => 60,
            'starts_at' => null,
            'ends_at' => null,
        ]);
        $this->makeQuestions($settings, 200);
        $contestant = $this->makeContestant($settings);

        $this->exam()->startOrResume($contestant->user, $settings);

        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00:00'));

        try {
            $this->exam()->state($contestant->refresh(), $settings);
        } catch (ExamException) {
            // Either outcome is acceptable here; the row is what matters.
        }

        $this->assertTrue($contestant->refresh()->isCompleted());

        Carbon::setTestNow();
    }

    public function test_a_manual_close_before_the_window_does_not_complete_anyone(): void
    {
        /*
         * The deliberate non-behaviour. `status = closed` is an operator switch,
         * not the passage of time, and effective_end has not been reached.
         * Finalising here would invent a business rule and would freeze scores
         * for contestants an operator might reopen to.
         */
        [$settings, $contestant] = $this->lateStarter();

        Carbon::setTestNow(Carbon::parse('2026-09-05 10:30:00'));
        $settings->forceFill(['status' => CompetitionSettings::STATUS_CLOSED])->save();

        try {
            $this->exam()->state($contestant->refresh(), $settings);
        } catch (ExamException) {
            // expected
        }

        $this->assertTrue($contestant->refresh()->isInProgress(), 'a manual close completed an exam');

        Carbon::setTestNow();
    }

    public function test_settling_is_idempotent_and_never_moves_completed_at(): void
    {
        [$settings, $contestant] = $this->lateStarter();

        Carbon::setTestNow(Carbon::parse('2026-09-05 11:00:00'));

        try {
            $this->exam()->state($contestant->refresh(), $settings);
        } catch (ExamException) {
        }

        $first = $contestant->refresh()->completed_at->toIso8601String();

        Carbon::setTestNow(Carbon::parse('2026-09-05 11:30:00'));

        try {
            $this->exam()->state($contestant->refresh(), $settings);
        } catch (ExamException) {
        }

        $this->assertSame($first, $contestant->refresh()->completed_at->toIso8601String());

        Carbon::setTestNow();
    }
}
