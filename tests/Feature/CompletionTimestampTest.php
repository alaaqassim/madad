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
 * `completed_at` must be the moment the exam ENDED, not the moment the server
 * noticed it had.
 *
 * ─── WHY THIS IS NOW LOAD-BEARING ───────────────────────────────────────────
 * Under the confirmed tie-break, contestants level on score are separated by
 * how long they took — completed_at − started_at, shortest first. So this
 * timestamp is no longer an audit nicety: it decides who takes the last place
 * in the Top 100.
 *
 * Finalisation is lazy by design: it happens on the first request after the
 * exam is over, because nothing else is watching. If completed_at simply took
 * `now()` at that point, a contestant whose exam ended at 09:50 but who did not
 * reopen the page until 11:30 would record a 2½-hour attempt and lose a tie
 * they had comfortably won. Every finalising path therefore passes the real
 * end: the last answer, the close of the last window, or effective_end.
 */
class CompletionTimestampTest extends TestCase
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

    /** @return array{0: CompetitionSettings, 1: CompetitionUser} */
    private function started(int $questions, array $overrides = []): array
    {
        $settings = $this->makeCompetition($overrides + ['question_count' => $questions]);
        $this->makeQuestions($settings, $questions);
        $contestant = $this->makeContestant($settings);

        $this->exam()->startOrResume($contestant->user, $settings);

        return [$settings, $contestant->refresh()];
    }

    public function test_finishing_by_answering_records_the_moment_of_the_last_answer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        [$settings, $contestant] = $this->started(3);

        foreach ([0, 1, 2] as $position) {
            Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00')->addSeconds(($position + 1) * 5));
            $this->exam()->submitAnswer($contestant->refresh(), $settings, null, 'A');
        }

        $contestant->refresh();

        $this->assertTrue($contestant->isCompleted());
        $this->assertSame($this->iso('2026-09-05 09:00:15'), $contestant->completed_at->toIso8601String());
        $this->assertSame(15, app(ResultService::class)->durationSeconds($contestant));
    }

    public function test_the_recorded_end_does_not_move_when_the_server_notices_late(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        // 3 questions x 40s = 120s of paper. The contestant answers nothing and
        // walks away; their paper is over at 09:02:00.
        [$settings, $contestant] = $this->started(3);

        // Nobody looks until nearly two hours later.
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:55:00'));

        $this->assertNull($this->exam()->currentQuestion($contestant->refresh(), $settings));

        $contestant->refresh();

        $this->assertTrue($contestant->isCompleted());
        $this->assertSame(
            $this->iso('2026-09-05 09:02:00'),
            $contestant->completed_at->toIso8601String(),
            'completed_at recorded when the server noticed, not when the exam ended',
        );
        $this->assertSame(120, app(ResultService::class)->durationSeconds($contestant));
    }

    public function test_running_out_of_time_records_the_deadline_not_the_visit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        // A paper far longer than the allowance, so the ALLOWANCE ends it.
        [$settings, $contestant] = $this->started(200, ['exam_duration_minutes' => 60]);

        $this->exam()->submitAnswer($contestant->refresh(), $settings, null, 'A');

        // Back the next morning.
        Carbon::setTestNow(Carbon::parse('2026-09-06 08:00:00'));

        $this->assertNull($this->exam()->currentQuestion($contestant->refresh(), $settings));

        $contestant->refresh();

        $this->assertSame(
            $this->iso('2026-09-05 10:00:00'),
            $contestant->completed_at->toIso8601String(),
            'the exam ended at its 60-minute deadline, whatever time anyone looked',
        );
        $this->assertSame(3600, app(ResultService::class)->durationSeconds($contestant));
    }

    public function test_the_window_closing_records_the_window_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:15:00'));

        [$settings, $contestant] = $this->started(200, [
            'exam_duration_minutes' => 60,
            'starts_at' => Carbon::parse('2026-09-05 09:00:00'),
            'ends_at' => Carbon::parse('2026-09-05 11:00:00'),
        ]);

        $this->exam()->submitAnswer($contestant->refresh(), $settings, null, 'A');

        // The window shut at 11:00; nobody looked until much later.
        Carbon::setTestNow(Carbon::parse('2026-09-05 15:00:00'));

        try {
            $this->exam()->currentQuestion($contestant->refresh(), $settings);
        } catch (\Throwable) {
            // The portal refuses after the window — settlement still happened.
        }

        $contestant->refresh();

        $this->assertTrue($contestant->isCompleted());
        $this->assertSame($this->iso('2026-09-05 11:00:00'), $contestant->completed_at->toIso8601String());
        // 10:15 to 11:00 is the 45 minutes the window actually allowed.
        $this->assertSame(2700, app(ResultService::class)->durationSeconds($contestant));
    }

    public function test_a_recorded_end_never_falls_outside_the_attempt(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        [$settings, $contestant] = $this->started(3);

        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00'));
        $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $contestant->refresh();
        $duration = app(ResultService::class)->durationSeconds($contestant);

        $this->assertGreaterThanOrEqual(0, $duration, 'an exam cannot end before it began');
        $this->assertLessThanOrEqual(
            $settings->examDurationSeconds(),
            $duration,
            'an exam cannot last longer than the allowance',
        );
    }

    public function test_two_contestants_who_walk_away_are_ranked_by_their_real_attempts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        $settings = $this->makeCompetition(['question_count' => 5, 'exam_duration_minutes' => 60]);
        $this->makeQuestions($settings, 5);

        $quick = $this->makeContestant($settings);
        $slow = $this->makeContestant($settings);

        // Both begin together. The quick one answers everything in 20 seconds.
        $this->exam()->startOrResume($quick->user, $settings);
        $this->exam()->startOrResume($slow->user, $settings);

        foreach ([0, 1, 2, 3, 4] as $position) {
            Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00')->addSeconds(($position + 1) * 4));
            $this->exam()->submitAnswer($quick->refresh(), $settings, null, $this->correctOptionAt($quick->refresh(), $position));
        }

        // The slow one answers four, then abandons; their fifth window closes
        // at 09:03:20 and the paper is over.
        foreach ([0, 1, 2, 3] as $position) {
            Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00')->addSeconds(($position + 1) * 30));
            $this->exam()->submitAnswer($slow->refresh(), $settings, null, $this->correctOptionAt($slow->refresh(), $position));
        }

        // Nobody touches the slow contestant again until the evening.
        Carbon::setTestNow(Carbon::parse('2026-09-05 20:00:00'));
        $this->exam()->currentQuestion($slow->refresh(), $settings);

        $quick->refresh();
        $slow->refresh();

        $this->assertSame(20, app(ResultService::class)->durationSeconds($quick));
        $this->assertSame(
            160,
            app(ResultService::class)->durationSeconds($slow),
            'the evening visit inflated the abandoned attempt',
        );

        // Level on score? No — the quick one answered all five. Force the
        // comparison by levelling the score, which is what a tie really is.
        $slow->forceFill(['correct_answers' => $quick->correct_answers])->save();

        $order = app(ResultService::class)->completed()->pluck('id')->all();

        $this->assertSame([$quick->id, $slow->id], $order, 'the shorter real attempt did not win');
    }
}
