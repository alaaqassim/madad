<?php

namespace Tests\Feature;

use App\Exceptions\ExamException;
use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Services\Competition\CompetitionExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * The fixed timeline: one anchor, and everything else derived from it.
 *
 *   slot i          [ started_at + i·s , started_at + (i+1)·s )
 *   time_index      floor( (now − started_at) / s )
 *   effective_end   min( started_at + personal_duration , settings.ends_at )
 *   expires_at      min( slot_end , effective_end )
 *
 * Nothing here reads an arrival, a reconnect, a disconnect or a device clock,
 * because none of those is stored or consulted. Every case below is arithmetic
 * on started_at and the server clock.
 */
class ExamTimelineTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private function exam(): CompetitionExamService
    {
        return app(CompetitionExamService::class);
    }

    /** @return array{0: CompetitionSettings, 1: CompetitionUser} */
    private function started(int $questions = 5, array $overrides = []): array
    {
        $settings = $this->makeCompetition($overrides + ['question_count' => $questions]);
        $this->makeQuestions($settings, $questions);
        $contestant = $this->makeContestant($settings);

        $this->exam()->startOrResume($contestant->user, $settings);

        return [$settings, $contestant->refresh()];
    }

    // ── the slot grid ───────────────────────────────────────────────────────

    public function test_the_first_slot_opens_at_started_at_with_a_full_window(): void
    {
        [$settings, $contestant] = $this->started(5);

        $payload = $this->exam()->currentQuestion($contestant, $settings);

        $this->assertSame(1, $payload['sequence']);
        $this->assertEqualsWithDelta(40.0, $payload['seconds_remaining'], 1.0);

        // opened_at is slot 0's start, which IS started_at. Both are derived.
        $this->assertSame($contestant->started_at->toIso8601String(), $payload['opened_at']);
        $this->assertSame(
            $contestant->started_at->copy()->addSeconds(40)->toIso8601String(),
            $payload['expires_at'],
        );
    }

    public function test_every_slot_is_exactly_forty_seconds_measured_from_started_at(): void
    {
        [$settings, $contestant] = $this->started(5);
        $startedAt = $contestant->started_at->copy();

        foreach ([0, 1, 2, 3, 4] as $index) {
            $this->enterSlot($contestant, $settings, $index);

            $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

            $this->assertSame($index + 1, $payload['sequence'], "slot {$index} was not live");
            $this->assertSame(
                $startedAt->copy()->addSeconds($index * 40)->toIso8601String(),
                $payload['opened_at'],
                "slot {$index} did not open at started_at + {$index}x40",
            );
            $this->assertSame(
                $startedAt->copy()->addSeconds(($index + 1) * 40)->toIso8601String(),
                $payload['expires_at'],
                "slot {$index} did not close at started_at + ".($index + 1).'x40',
            );
        }
    }

    public function test_a_refresh_does_not_extend_the_window(): void
    {
        [$settings, $contestant] = $this->started(5);

        $first = $this->exam()->currentQuestion($contestant, $settings);

        $this->travel(12)->seconds();

        $second = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertSame($first['question_id'], $second['question_id']);
        $this->assertSame($first['expires_at'], $second['expires_at'], 'the deadline moved');
        $this->assertEqualsWithDelta(28.0, $second['seconds_remaining'], 1.0);
    }

    public function test_seconds_remaining_never_exceeds_seconds_per_question(): void
    {
        [$settings, $contestant] = $this->started(5);

        foreach ([0, 1, 2, 3, 4] as $index) {
            // Land right at the instant the slot opens: the most generous
            // moment there is, and still exactly one window.
            $this->enterSlot($contestant, $settings, $index, 0);

            $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

            $this->assertLessThanOrEqual(
                40.0,
                $payload['seconds_remaining'],
                "slot {$index} was given more than one window",
            );
        }
    }

    // ── answering early does not shift anything ─────────────────────────────

    public function test_answering_early_does_not_move_the_next_slot(): void
    {
        [$settings, $contestant] = $this->started(5);
        $startedAt = $contestant->started_at->copy();

        // Five seconds in. Slot 1 still owns started_at+40 → started_at+80.
        $this->travel(5)->seconds();
        $this->exam()->submitAnswer($contestant, $settings, $contestant->questionIdAt(0), 'A');

        $this->travel(40)->seconds();   // now started_at + 45, inside slot 1

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertSame(2, $payload['sequence']);
        $this->assertSame(
            $startedAt->copy()->addSeconds(40)->toIso8601String(),
            $payload['opened_at'],
            'answering early created a new window at the answer time',
        );
        $this->assertSame(
            $startedAt->copy()->addSeconds(80)->toIso8601String(),
            $payload['expires_at'],
            'answering early moved the next deadline',
        );
        $this->assertEqualsWithDelta(35.0, $payload['seconds_remaining'], 1.0);
    }

    public function test_answering_early_yields_a_waiting_state_until_the_next_slot_opens(): void
    {
        [$settings, $contestant] = $this->started(5);
        $startedAt = $contestant->started_at->copy();

        $this->travel(5)->seconds();
        $this->exam()->submitAnswer($contestant, $settings, null, 'A');

        $state = $this->exam()->state($contestant->refresh(), $settings);

        $this->assertNull($state['question'], 'a question was served outside its own slot');
        $this->assertNotNull($state['waiting'], 'there was no waiting state');
        $this->assertSame(CompetitionUser::EXAM_IN_PROGRESS, $state['exam_status']);

        $this->assertSame(2, $state['waiting']['sequence']);
        $this->assertSame($startedAt->copy()->addSeconds(40)->toIso8601String(), $state['waiting']['opens_at']);
        // 35 of the 40 seconds of slot 0 are left over, and that is the wait.
        $this->assertEqualsWithDelta(35.0, $state['waiting']['seconds_remaining'], 1.0);
        $this->assertLessThanOrEqual(40.0, $state['waiting']['seconds_remaining']);
    }

    public function test_a_contestant_waiting_for_the_next_slot_cannot_answer_yet(): void
    {
        [$settings, $contestant] = $this->started(5);

        $this->travel(5)->seconds();
        $this->exam()->submitAnswer($contestant, $settings, null, 'A');

        $this->expectExceptionMessage('That question is not available.');

        $this->exam()->submitAnswer($contestant->refresh(), $settings, null, 'B');
    }

    public function test_the_wait_ends_exactly_when_the_slot_opens(): void
    {
        [$settings, $contestant] = $this->started(5);

        $this->travel(5)->seconds();
        $this->exam()->submitAnswer($contestant, $settings, null, 'A');

        $this->enterSlot($contestant, $settings, 1, 0);

        $state = $this->exam()->state($contestant->refresh(), $settings);

        $this->assertNull($state['waiting'], 'the wait outlived its own slot boundary');
        $this->assertNotNull($state['question']);
        $this->assertSame(2, $state['question']['sequence']);
    }

    public function test_the_position_never_moves_backwards(): void
    {
        [$settings, $contestant] = $this->started(75);

        // Answer three questions, each inside its own slot.
        foreach ([0, 1, 2] as $index) {
            $this->enterSlot($contestant, $settings, $index);
            $this->exam()->submitAnswer($contestant->refresh(), $settings, null, 'A');
        }

        $this->assertSame(3, $contestant->refresh()->current_question);

        // Still inside slot 2 on the wall clock, but the contestant is at 3.
        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertNull($payload, 'the contestant is ahead of the clock and should be waiting');
        $this->assertSame(3, $contestant->refresh()->current_question, 'the timeline dragged them backwards');
    }

    // ── time never pauses ───────────────────────────────────────────────────

    /**
     * The reconnect the business asked about, to the second.
     *
     *   start 08:00:00, return 08:15:00 → elapsed 900s, 900/40 = 22.5 → index 22
     */
    public function test_returning_after_fifteen_minutes_resumes_at_the_real_position(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 08:00:00'));

        [$settings, $contestant] = $this->started(75);

        $this->exam()->currentQuestion($contestant, $settings);
        $this->assertSame(0, $contestant->refresh()->current_question);

        // The contestant disconnects. Fifteen minutes of real time pass.
        Carbon::setTestNow(Carbon::parse('2026-09-05 08:15:00'));

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertSame(22, $contestant->refresh()->current_question, 'the elapsed timeline was not applied');
        $this->assertSame(23, $payload['sequence']);

        // Slot 22 runs 08:14:40 → 08:15:20, so twenty seconds of it survive.
        $this->assertSame('2026-09-05T08:14:40+00:00', $payload['opened_at']);
        $this->assertSame('2026-09-05T08:15:20+00:00', $payload['expires_at']);
        $this->assertEqualsWithDelta(20.0, $payload['seconds_remaining'], 0.5);

        // The twenty-two positions the clock passed are spent, permanently.
        $this->assertSame(str_repeat(CompetitionUser::NO_ANSWER, 22), substr($contestant->answers, 0, 22));
        $this->assertSame(0, $contestant->answered_questions);

        Carbon::setTestNow();
    }

    public function test_a_disconnect_does_not_pause_the_clock(): void
    {
        [$settings, $contestant] = $this->started(75);

        // Answer position 0, then vanish for ten minutes.
        $this->exam()->submitAnswer($contestant, $settings, null, 'A');
        $this->travel(600)->seconds();

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertSame(15, $contestant->refresh()->current_question, '600s / 40s = 15');
        $this->assertSame(16, $payload['sequence']);
        $this->assertNotSame(2, $payload['sequence'], 'the contestant resumed where they left off');
        $this->assertLessThanOrEqual(40.0, $payload['seconds_remaining']);
        $this->assertSame(1, $contestant->answered_questions, 'the elapsed slots were counted as answered');
    }

    public function test_no_timing_is_taken_from_arrival_or_reconnect(): void
    {
        [$settings, $contestant] = $this->started(75);
        $startedAt = $contestant->started_at->copy();

        // A contestant who reconnects at an arbitrary moment gets the slot the
        // clock says, with the deadline the grid says — not a fresh window
        // beginning at the moment they showed up.
        $this->travel(410)->seconds();   // 10 slots and 10 seconds

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertSame(11, $payload['sequence']);
        $this->assertSame($startedAt->copy()->addSeconds(400)->toIso8601String(), $payload['opened_at']);
        $this->assertSame($startedAt->copy()->addSeconds(440)->toIso8601String(), $payload['expires_at']);
        $this->assertEqualsWithDelta(30.0, $payload['seconds_remaining'], 0.5, 'a fresh window was opened on reconnect');
    }

    public function test_the_device_clock_has_no_authority(): void
    {
        [$settings, $contestant] = $this->started(5);

        // A client insisting it is next year changes nothing: no request header
        // and no request field is consulted for timing at any point.
        $payload = $this->actingAs($contestant->user)
            ->getJson('/api/exam/current', [
                'X-Client-Time' => now()->addYear()->toIso8601String(),
                'Date' => now()->subYear()->toRfc7231String(),
            ])
            ->assertOk()
            ->json('question');

        $this->assertEqualsWithDelta(
            0,
            Carbon::parse($payload['server_time'])->diffInSeconds(now()),
            2,
        );
        $this->assertEqualsWithDelta(40.0, $payload['seconds_remaining'], 1.0);
        $this->assertSame(1, $payload['sequence']);
    }

    // ── the personal 60-minute allowance ────────────────────────────────────

    public function test_the_personal_allowance_is_sixty_minutes(): void
    {
        $settings = $this->makeCompetition(['question_count' => 200, 'exam_duration_minutes' => 60]);

        $this->assertSame(3600, $settings->examDurationSeconds());
        $this->assertSame(
            '2026-09-05T10:00:00+00:00',
            $settings->effectiveEndFor(Carbon::parse('2026-09-05 09:00:00'))->toIso8601String(),
        );
    }

    public function test_a_paper_longer_than_the_allowance_is_cut_off_at_sixty_minutes(): void
    {
        // 200 x 40s = 8000s of slots, but only 3600s of allowance.
        [$settings, $contestant] = $this->started(200, ['exam_duration_minutes' => 60]);

        $this->travel(3599)->seconds();

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertNotNull($payload, 'the exam ended before the allowance did');
        $this->assertSame(90, $payload['sequence'], '3599s / 40s = slot 89');
        // Slot 89 would run to 3600s anyway; the allowance is the binding end.
        $this->assertEqualsWithDelta(1.0, $payload['seconds_remaining'], 0.5);

        $this->travel(2)->seconds();

        $this->assertNull($this->exam()->currentQuestion($contestant->refresh(), $settings));
        $this->assertSame(CompetitionUser::EXAM_COMPLETED, $contestant->refresh()->exam_status);
    }

    public function test_reconnecting_after_the_personal_deadline_completes_the_exam(): void
    {
        [$settings, $contestant] = $this->started(200, ['exam_duration_minutes' => 60]);

        $this->exam()->submitAnswer($contestant, $settings, null, 'A');

        // Back an hour and a half later: their hour is long gone.
        $this->travel(5400)->seconds();

        $this->assertNull($this->exam()->currentQuestion($contestant->refresh(), $settings));

        $contestant->refresh();

        $this->assertSame(CompetitionUser::EXAM_COMPLETED, $contestant->exam_status);
        $this->assertNotNull($contestant->completed_at);
        // The one answer they did submit survives; nothing else is invented.
        $this->assertSame(1, $contestant->answered_questions);
    }

    public function test_an_answer_after_the_personal_deadline_is_refused(): void
    {
        [$settings, $contestant] = $this->started(200, ['exam_duration_minutes' => 60]);

        $this->travel(3601)->seconds();

        $this->expectExceptionMessage('Your exam is already complete.');

        $this->exam()->submitAnswer($contestant->refresh(), $settings, null, 'A');
    }

    public function test_the_paper_still_ends_when_its_slots_run_out_before_the_allowance(): void
    {
        // 75 x 40 = 3000s, comfortably inside the 3600s allowance, so the paper
        // is what ends the exam — the allowance is a ceiling, not a floor.
        [$settings, $contestant] = $this->started(75, ['exam_duration_minutes' => 60]);

        $this->travel(2999)->seconds();

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);
        $this->assertNotNull($payload);
        $this->assertSame(75, $payload['sequence']);

        $this->travel(2)->seconds();

        $this->assertNull($this->exam()->currentQuestion($contestant->refresh(), $settings));
        $this->assertSame(CompetitionUser::EXAM_COMPLETED, $contestant->refresh()->exam_status);
    }

    // ── the global availability window ──────────────────────────────────────

    public function test_a_late_start_is_capped_by_the_window_not_the_allowance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:15:00'));

        // Window 09:00 → 11:00, allowance 60 minutes. Beginning at 10:15 would
        // personally end at 11:15, but the window shuts at 11:00.
        $settings = $this->makeCompetition([
            'question_count' => 200,
            'exam_duration_minutes' => 60,
            'starts_at' => Carbon::parse('2026-09-05 09:00:00'),
            'ends_at' => Carbon::parse('2026-09-05 11:00:00'),
        ]);

        $this->assertSame(
            '2026-09-05T11:00:00+00:00',
            $settings->effectiveEndFor(now())->toIso8601String(),
            'the contestant was granted time beyond the window',
        );
        $this->assertSame(2700, $settings->secondsAvailableFrom(), '45 minutes, not 60');

        Carbon::setTestNow();
    }

    public function test_a_contestant_cannot_continue_past_the_window(): void
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
        $this->exam()->submitAnswer($contestant->refresh(), $settings, null, 'A');

        // 10:59:59 — still inside, and the last slot is trimmed to the window.
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:59:59'));

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertNotNull($payload);
        $this->assertSame('2026-09-05T11:00:00+00:00', $payload['expires_at'], 'the slot ran past the window');
        $this->assertEqualsWithDelta(1.0, $payload['seconds_remaining'], 0.5);

        // 11:00:00 — the window is over, and so is the exam.
        Carbon::setTestNow(Carbon::parse('2026-09-05 11:00:00'));

        $this->expectExceptionMessage('The competition has ended.');

        try {
            $this->exam()->currentQuestion($contestant->refresh(), $settings);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_contestant_may_not_begin_before_the_window_opens(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 08:59:59'));

        $settings = $this->makeCompetition([
            'question_count' => 5,
            'starts_at' => Carbon::parse('2026-09-05 09:00:00'),
            'ends_at' => Carbon::parse('2026-09-05 11:00:00'),
        ]);
        $this->makeQuestions($settings, 5);
        $contestant = $this->makeContestant($settings);

        try {
            $this->exam()->startOrResume($contestant->user, $settings);
            $this->fail('a contestant began before the window opened');
        } catch (ExamException $e) {
            // Not terminal: one second later it will work.
            $this->assertSame('competition_not_open', $e->reason);
        }

        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        $this->exam()->startOrResume($contestant->user, $settings);
        $this->assertSame(CompetitionUser::EXAM_IN_PROGRESS, $contestant->refresh()->exam_status);

        Carbon::setTestNow();
    }

    public function test_a_contestant_may_not_begin_after_the_window_closes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 11:00:00'));

        $settings = $this->makeCompetition([
            'question_count' => 5,
            'starts_at' => Carbon::parse('2026-09-05 09:00:00'),
            'ends_at' => Carbon::parse('2026-09-05 11:00:00'),
        ]);
        $this->makeQuestions($settings, 5);
        $contestant = $this->makeContestant($settings);

        try {
            $this->exam()->startOrResume($contestant->user, $settings);
            $this->fail('a contestant began after the window closed');
        } catch (ExamException $e) {
            // Terminal: the client must not offer "try again later".
            $this->assertSame('competition_closed', $e->reason);
        }

        $this->assertSame(CompetitionUser::EXAM_NOT_STARTED, $contestant->refresh()->exam_status);
        $this->assertNull($contestant->started_at);

        Carbon::setTestNow();
    }

    public function test_no_window_means_status_alone_governs_access(): void
    {
        [$settings, $contestant] = $this->started(5);

        $this->assertNull($settings->starts_at);
        $this->assertNull($settings->ends_at);
        $this->assertTrue($settings->withinWindow());
        $this->assertNotNull($this->exam()->currentQuestion($contestant, $settings));
    }

    // ── the payload ─────────────────────────────────────────────────────────

    public function test_the_window_is_taken_from_the_settings_row(): void
    {
        [$settings, $contestant] = $this->started(5, ['seconds_per_question' => 15]);

        $payload = $this->exam()->currentQuestion($contestant, $settings);

        $this->assertEqualsWithDelta(15.0, $payload['seconds_remaining'], 1.0);
        $this->assertSame(
            Carbon::parse($payload['opened_at'])->addSeconds(15)->toIso8601String(),
            $payload['expires_at'],
        );
    }

    public function test_the_payload_never_carries_the_answer_key(): void
    {
        [$settings, $contestant] = $this->started(5);

        $payload = $this->exam()->currentQuestion($contestant, $settings);

        $this->assertSame([
            'question_id', 'question_text', 'options', 'sequence', 'total_questions',
            'opened_at', 'expires_at', 'server_time', 'seconds_remaining',
        ], array_keys($payload));

        $this->assertArrayNotHasKey('correct_option', $payload);
        $this->assertArrayNotHasKey('is_correct', $payload);
    }

    public function test_the_waiting_payload_carries_no_question_and_no_options(): void
    {
        [$settings, $contestant] = $this->started(5);

        $this->travel(5)->seconds();
        $this->exam()->submitAnswer($contestant, $settings, null, 'A');

        $waiting = $this->exam()->state($contestant->refresh(), $settings)['waiting'];

        $this->assertSame(
            ['sequence', 'total_questions', 'opens_at', 'server_time', 'seconds_remaining'],
            array_keys($waiting),
        );
        $this->assertArrayNotHasKey('question_id', $waiting);
        $this->assertArrayNotHasKey('options', $waiting);
        $this->assertArrayNotHasKey('correct_option', $waiting);
    }
}
