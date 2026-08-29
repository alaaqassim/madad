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
 * The timeline: two stored anchors, no stored expiry.
 *
 *   started_at                    bounds the ATTEMPT
 *   current_question_started_at   when the LIVE question became live
 *
 *   effective_end    min( started_at + personal_duration , settings.ends_at )
 *   expires_at       min( current_question_started_at + s , effective_end )
 *   windows_elapsed  floor( (now − current_question_started_at) / s )
 *
 * Answering ADVANCES IMMEDIATELY: the next question is live at the instant the
 * answer lands, with a window of its own. What does NOT move is the attempt —
 * answering fast buys questions, never minutes — and what is never given back
 * is time spent away, because reconciliation consumes whole windows rather than
 * restarting the clock at `now`.
 *
 * Nothing here reads a device clock, a request header or a session value.
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
        /*
         * Freeze the clock.
         *
         * These cases assert remaining seconds to within a second, so without
         * this they are really asserting how fast the machine is: the real time
         * spent between Begin and the assertion comes straight off the answer.
         * That passes on a developer's laptop and fails on slower CI hardware,
         * which is a test defect, not a finding.
         *
         * Cases that set their own Carbon::setTestNow() first are unaffected -
         * freezing at now() keeps whatever they chose - and travel() still
         * moves the clock deliberately.
         */
        $this->freezeTime();

        $settings = $this->makeCompetition($overrides + ['question_count' => $questions]);
        $this->makeQuestions($settings, $questions);
        $contestant = $this->makeContestant($settings);

        $this->exam()->startOrResume($contestant->user, $settings);

        return [$settings, $contestant->refresh()];
    }

    // ── the question window ─────────────────────────────────────────────────

    public function test_the_first_question_opens_at_started_at_with_a_full_window(): void
    {
        [$settings, $contestant] = $this->started(5);

        $payload = $this->exam()->currentQuestion($contestant, $settings);

        $this->assertSame(1, $payload['sequence']);
        $this->assertEqualsWithDelta(40.0, $payload['seconds_remaining'], 1.0);

        // At index 0 the two anchors coincide, and both are stored.
        $this->assertSame($contestant->started_at->toIso8601String(), $payload['opened_at']);
        $this->assertSame(
            $contestant->started_at->copy()->addSeconds(40)->toIso8601String(),
            $payload['expires_at'],
        );
        $this->assertSame(
            $contestant->started_at->toIso8601String(),
            $contestant->current_question_started_at->toIso8601String(),
        );
    }

    public function test_answering_after_five_seconds_serves_the_next_question_immediately(): void
    {
        [$settings, $contestant] = $this->started(5);
        $startedAt = $contestant->started_at->copy();

        $this->travel(5)->seconds();
        $this->exam()->submitAnswer($contestant, $settings, $contestant->questionIdAt(0), 'A');

        // No clock movement between the answer and this read: whatever is live
        // is live NOW, five seconds into the attempt.
        $state = $this->exam()->state($contestant->refresh(), $settings);

        $this->assertNotNull($state['question'], 'the contestant was made to wait after an early answer');
        $this->assertSame(2, $state['question']['sequence']);
        $this->assertSame(CompetitionUser::EXAM_IN_PROGRESS, $state['exam_status']);

        // The new window begins at the answer, not at a grid position.
        $this->assertSame($startedAt->copy()->addSeconds(5)->toIso8601String(), $state['question']['opened_at']);
        $this->assertSame($startedAt->copy()->addSeconds(45)->toIso8601String(), $state['question']['expires_at']);
        $this->assertEqualsWithDelta(40.0, $state['question']['seconds_remaining'], 0.5);
    }

    public function test_the_next_question_is_anchored_at_the_server_timestamp_of_the_answer(): void
    {
        [$settings, $contestant] = $this->started(5);
        $startedAt = $contestant->started_at->copy();

        $this->travel(5)->seconds();
        $this->exam()->submitAnswer($contestant, $settings, null, 'A');

        // The anchor is DURABLE, not a value the payload invented: it is on the
        // row, and it is the moment the answer landed.
        $this->assertSame(
            $startedAt->copy()->addSeconds(5)->toIso8601String(),
            $contestant->refresh()->current_question_started_at->toIso8601String(),
        );
    }

    public function test_a_question_never_gets_more_than_seconds_per_question(): void
    {
        [$settings, $contestant] = $this->started(5);

        foreach ([0, 1, 2, 3] as $index) {
            $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

            $this->assertSame($index + 1, $payload['sequence']);
            $this->assertLessThanOrEqual(
                40.0,
                $payload['seconds_remaining'],
                "position {$index} was given more than one window",
            );

            // Answer instantly. Under immediate advance the next question is
            // live at once — and it must still be capped at one window.
            $this->exam()->submitAnswer($contestant->refresh(), $settings, null, 'A');
        }
    }

    public function test_a_refresh_does_not_extend_the_current_deadline(): void
    {
        [$settings, $contestant] = $this->started(5);

        $first = $this->exam()->currentQuestion($contestant, $settings);

        $this->travel(12)->seconds();

        $second = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertSame($first['question_id'], $second['question_id']);
        $this->assertSame($first['opened_at'], $second['opened_at'], 'the anchor moved on a refresh');
        $this->assertSame($first['expires_at'], $second['expires_at'], 'the deadline moved');
        $this->assertEqualsWithDelta(28.0, $second['seconds_remaining'], 1.0);
    }

    public function test_there_is_no_waiting_state_after_an_early_answer(): void
    {
        [$settings, $contestant] = $this->started(5);

        $this->travel(5)->seconds();
        $this->exam()->submitAnswer($contestant, $settings, null, 'A');

        $state = $this->exam()->state($contestant->refresh(), $settings);

        $this->assertSame(['exam_status', 'started_at', 'question'], array_keys($state));
        $this->assertArrayNotHasKey('waiting', $state);
        $this->assertNotNull($state['question']);
    }

    public function test_the_answer_response_itself_carries_the_next_question(): void
    {
        [$settings, $contestant] = $this->started(5);

        $this->travel(5)->seconds();

        $body = $this->actingAs($contestant->user)
            ->postJson('/api/exam/answer', [
                'question_id' => $contestant->questionIdAt(0),
                'selected_option' => 'A',
            ])
            ->assertOk()
            ->json();

        // No follow-up round trip: the tail of the answer IS the next state.
        $this->assertTrue($body['accepted']);
        $this->assertArrayNotHasKey('waiting', $body);
        $this->assertNotNull($body['next_question']);
        $this->assertSame(2, $body['next_question']['sequence']);
        $this->assertEqualsWithDelta(40.0, $body['next_question']['seconds_remaining'], 0.5);
    }

    // ── a window that closes unanswered ─────────────────────────────────────

    public function test_an_unanswered_window_is_spent_and_the_next_one_opens(): void
    {
        [$settings, $contestant] = $this->started(5);
        $startedAt = $contestant->started_at->copy();

        $this->travel(41)->seconds();

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertSame(2, $payload['sequence']);
        $this->assertSame(CompetitionUser::NO_ANSWER, substr((string) $contestant->refresh()->answers, 0, 1));
        $this->assertSame(0, $contestant->refresh()->answered_questions);

        // The next window opened when the previous one CLOSED — 40 seconds in —
        // not when the contestant happened to ask, 41 seconds in.
        $this->assertSame($startedAt->copy()->addSeconds(40)->toIso8601String(), $payload['opened_at']);
        $this->assertSame($startedAt->copy()->addSeconds(80)->toIso8601String(), $payload['expires_at']);
        $this->assertEqualsWithDelta(39.0, $payload['seconds_remaining'], 0.5);
    }

    public function test_a_timeout_noticed_late_does_not_extend_the_question_that_follows(): void
    {
        [$settings, $contestant] = $this->started(5);
        $startedAt = $contestant->started_at->copy();

        // Fifteen seconds past the deadline before anyone asks.
        $this->travel(55)->seconds();

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertSame(2, $payload['sequence']);
        $this->assertSame($startedAt->copy()->addSeconds(80)->toIso8601String(), $payload['expires_at']);
        $this->assertEqualsWithDelta(
            25.0,
            $payload['seconds_remaining'],
            0.5,
            'the overshoot was handed back as extra time',
        );
    }

    // ── time never pauses ───────────────────────────────────────────────────

    /**
     * The reconnect from the specification, to the second.
     *
     *   started_at = 08:00:00        answer Q1 at 08:00:05  →  Q2 opens 08:00:05
     *   disconnect 08:00:10          return    08:02:00
     *
     *   115 seconds since the anchor / 40 = 2 whole windows consumed.
     */
    public function test_the_specified_disconnect_consumes_whole_windows_from_the_anchor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 08:00:00'));

        [$settings, $contestant] = $this->started(75);

        Carbon::setTestNow(Carbon::parse('2026-09-05 08:00:05'));
        $this->exam()->submitAnswer($contestant, $settings, null, 'A');

        $this->assertSame(1, $contestant->refresh()->current_question);
        $this->assertSame(
            $this->iso('2026-09-05 08:00:05'),
            $contestant->refresh()->current_question_started_at->toIso8601String(),
        );

        // Gone from 08:00:10 to 08:02:00. No request in between.
        Carbon::setTestNow(Carbon::parse('2026-09-05 08:02:00'));

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        // NOT Q2 with a fresh 40 seconds: two windows ran out while they were
        // away, so they rejoin at position 3 (sequence 4).
        $this->assertSame(3, $contestant->refresh()->current_question);
        $this->assertSame(4, $payload['sequence']);
        $this->assertSame($this->iso('2026-09-05 08:01:25'), $payload['opened_at'], '08:00:05 + 2x40');
        $this->assertSame($this->iso('2026-09-05 08:02:05'), $payload['expires_at']);
        $this->assertEqualsWithDelta(5.0, $payload['seconds_remaining'], 0.5);

        // Positions 1 and 2 are spent, permanently, and scored nothing.
        $this->assertSame('--', substr((string) $contestant->refresh()->answers, 1, 2));
        $this->assertSame(1, $contestant->refresh()->answered_questions);

        Carbon::setTestNow();
    }

    public function test_returning_after_fifteen_minutes_resumes_at_the_real_position(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 08:00:00'));

        [$settings, $contestant] = $this->started(75);

        $this->exam()->currentQuestion($contestant, $settings);
        $this->assertSame(0, $contestant->refresh()->current_question);

        // The contestant disconnects without answering. Fifteen minutes pass.
        Carbon::setTestNow(Carbon::parse('2026-09-05 08:15:00'));

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertSame(22, $contestant->refresh()->current_question, '900s / 40s = 22 windows');
        $this->assertSame(23, $payload['sequence']);
        $this->assertSame($this->iso('2026-09-05 08:14:40'), $payload['opened_at']);
        $this->assertSame($this->iso('2026-09-05 08:15:20'), $payload['expires_at']);
        $this->assertEqualsWithDelta(20.0, $payload['seconds_remaining'], 0.5);

        $this->assertSame(str_repeat(CompetitionUser::NO_ANSWER, 22), substr($contestant->answers, 0, 22));
        $this->assertSame(0, $contestant->answered_questions);

        Carbon::setTestNow();
    }

    public function test_a_long_disconnect_skips_many_windows_at_once(): void
    {
        [$settings, $contestant] = $this->started(75);
        $startedAt = $contestant->started_at->copy();

        // Answer position 0 immediately, then vanish for ten minutes.
        $this->exam()->submitAnswer($contestant, $settings, null, 'A');
        $this->travel(610)->seconds();

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertSame(16, $contestant->refresh()->current_question, '610s / 40s = 15 windows, from index 1');
        $this->assertSame(17, $payload['sequence']);
        $this->assertNotSame(2, $payload['sequence'], 'the contestant resumed where they left off');
        $this->assertSame($startedAt->copy()->addSeconds(600)->toIso8601String(), $payload['opened_at']);
        $this->assertEqualsWithDelta(30.0, $payload['seconds_remaining'], 0.5);
        $this->assertSame(1, $contestant->refresh()->answered_questions, 'skipped windows were counted as answers');
    }

    public function test_a_reconnect_does_not_open_a_fresh_window(): void
    {
        [$settings, $contestant] = $this->started(75);
        $startedAt = $contestant->started_at->copy();

        // Ten windows and ten seconds after the anchor. The contestant shows up
        // at an arbitrary moment; the window they land in is already running.
        $this->travel(410)->seconds();

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertSame(11, $payload['sequence']);
        $this->assertSame($startedAt->copy()->addSeconds(400)->toIso8601String(), $payload['opened_at']);
        $this->assertSame($startedAt->copy()->addSeconds(440)->toIso8601String(), $payload['expires_at']);
        $this->assertEqualsWithDelta(
            30.0,
            $payload['seconds_remaining'],
            0.5,
            'a fresh window was opened at the moment of reconnection',
        );
    }

    public function test_the_position_never_moves_backwards(): void
    {
        [$settings, $contestant] = $this->started(75);

        // Three questions answered in the first few seconds — legitimate under
        // immediate advance, and far ahead of where a fixed grid would allow.
        foreach ([0, 1, 2] as $index) {
            $this->exam()->submitAnswer($contestant->refresh(), $settings, null, 'A');
        }

        $this->assertSame(3, $contestant->refresh()->current_question);

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertSame(4, $payload['sequence']);
        $this->assertSame(3, $contestant->refresh()->current_question, 'the contestant was dragged backwards');
    }

    public function test_the_database_is_the_source_of_progress_not_the_request(): void
    {
        [$settings, $contestant] = $this->started(5);

        $this->exam()->submitAnswer($contestant, $settings, null, 'A');

        // A completely separate read, with a fresh model instance, sees the
        // same anchor and the same position.
        $reread = CompetitionUser::query()->findOrFail($contestant->id);

        $this->assertSame(1, (int) $reread->current_question);
        $this->assertSame(
            $contestant->refresh()->current_question_started_at->toIso8601String(),
            $reread->current_question_started_at->toIso8601String(),
        );
        $this->assertSame(2, $this->exam()->currentQuestion($reread, $settings)['sequence']);
    }

    public function test_losing_the_session_does_not_reset_the_question_timer(): void
    {
        [$settings, $contestant] = $this->started(5);

        $this->actingAs($contestant->user)->postJson('/api/exam/start')->assertOk();
        $this->travel(25)->seconds();

        $anchor = $contestant->refresh()->current_question_started_at->toIso8601String();

        // Session gone: a new one carries no exam state whatsoever.
        $this->post('/api/logout');
        session()->flush();

        $payload = $this->actingAs($contestant->user)
            ->getJson('/api/exam/current')
            ->assertOk()
            ->json('question');

        $this->assertSame($anchor, $payload['opened_at'], 'the timer restarted with the session');
        $this->assertEqualsWithDelta(15.0, $payload['seconds_remaining'], 1.0);
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
            $this->iso('2026-09-05 10:00:00'),
            $settings->effectiveEndFor(Carbon::parse('2026-09-05 09:00:00'))->toIso8601String(),
        );
    }

    public function test_answering_fast_buys_questions_not_minutes(): void
    {
        [$settings, $contestant] = $this->started(200, ['exam_duration_minutes' => 60]);
        $startedAt = $contestant->started_at->copy();

        // Ten questions answered in ten seconds. The attempt's end has not
        // moved by a millisecond — it runs from started_at, and nothing else.
        for ($i = 0; $i < 10; $i++) {
            $this->travel(1)->seconds();
            $this->exam()->submitAnswer($contestant->refresh(), $settings, null, 'A');
        }

        $this->assertSame(10, (int) $contestant->refresh()->current_question);
        $this->assertSame(
            $startedAt->copy()->addSeconds(3600)->toIso8601String(),
            $settings->effectiveEndFor($contestant->refresh()->started_at)->toIso8601String(),
        );
        $this->assertSame(
            $startedAt->toIso8601String(),
            $contestant->refresh()->started_at->toIso8601String(),
            'the attempt anchor moved',
        );
    }

    public function test_a_paper_longer_than_the_allowance_is_cut_off_at_sixty_minutes(): void
    {
        // 200 x 40s = 8000s of windows, but only 3600s of allowance.
        [$settings, $contestant] = $this->started(200, ['exam_duration_minutes' => 60]);

        $this->travel(3599)->seconds();

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertNotNull($payload, 'the exam ended before the allowance did');
        $this->assertSame(90, $payload['sequence'], '3599s / 40s = 89 windows consumed');
        // The window would run to 3600s anyway; the allowance is the binding end.
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
        $this->assertNull($contestant->current_question_started_at, 'a finished exam kept a live-question anchor');
    }

    public function test_an_answer_after_the_personal_deadline_is_refused(): void
    {
        [$settings, $contestant] = $this->started(200, ['exam_duration_minutes' => 60]);

        $this->travel(3601)->seconds();

        $this->expectExceptionMessage('Your exam is already complete.');

        $this->exam()->submitAnswer($contestant->refresh(), $settings, null, 'A');
    }

    public function test_the_paper_still_ends_when_its_questions_run_out_before_the_allowance(): void
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
            $this->iso('2026-09-05 11:00:00'),
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

        // 10:59:59 — still inside, and the last window is trimmed to the window.
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:59:59'));

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertNotNull($payload);
        $this->assertSame($this->iso('2026-09-05 11:00:00'), $payload['expires_at'], 'a question ran past the window');
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
        $this->assertNull($contestant->current_question_started_at);

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

    // ── stale and duplicate submissions ─────────────────────────────────────

    public function test_answering_the_previous_question_again_is_refused(): void
    {
        [$settings, $contestant] = $this->started(5);

        $answeredId = $contestant->questionIdAt(0);
        $this->exam()->submitAnswer($contestant, $settings, $answeredId, 'A');

        $this->expectExceptionMessage('That question is not available.');

        // The client is a beat behind and re-sends the question it just
        // answered. It must not be recorded twice, and must not move anything.
        $this->exam()->submitAnswer($contestant->refresh(), $settings, $answeredId, 'B');
    }

    public function test_answering_a_question_further_down_the_paper_is_refused(): void
    {
        [$settings, $contestant] = $this->started(5);

        $this->expectExceptionMessage('That question is not available.');

        $this->exam()->submitAnswer($contestant, $settings, $contestant->questionIdAt(3), 'A');
    }

    // ── the payload ─────────────────────────────────────────────────────────

    public function test_the_window_length_is_taken_from_the_settings_row(): void
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
}
