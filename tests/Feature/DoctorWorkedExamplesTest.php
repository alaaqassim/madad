<?php

namespace Tests\Feature;

use App\Exceptions\ExamException;
use App\Models\CompetitionUser;
use App\Services\Competition\CompetitionExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * The three worked examples from the confirmed specification, verbatim.
 *
 * Every number below is the specification's own — 08:00, 08:15, 40 seconds,
 * index 22; a window ending 11:00 with a 10:15 start and a 60-minute
 * allowance; Q1 answered at 08:00:05 with Q2 live at 08:00:05, and a
 * disconnect from 08:00:10 to 08:02:00. Nothing here is a paraphrase, and
 * nothing is recomputed from the engine's own arithmetic: the expected values
 * are literals, so the test disagrees with the engine rather than agreeing
 * with it by construction.
 *
 * The behaviour is covered in depth by ExamTimelineTest and ExamSettlementTest.
 * This file exists so the examples the business stated can be pointed at
 * directly, by name, in one place.
 */
class DoctorWorkedExamplesTest extends TestCase
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

    /**
     * A. The question anchor is 08:00 (nothing has been answered), now = 08:15,
     *    s = 40  →  floor(900 / 40) = 22 whole windows consumed.
     */
    public function test_example_a_a_fifteen_minute_absence_lands_on_index_twenty_two(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 08:00:00'));

        $settings = $this->makeCompetition([
            'question_count' => 75,
            'seconds_per_question' => 40,
            'exam_duration_minutes' => 60,
        ]);
        $this->makeQuestions($settings, 75);
        $contestant = $this->makeContestant($settings);

        $this->exam()->startOrResume($contestant->user, $settings);

        $this->assertSame('2026-09-05T08:00:00+00:00', $contestant->refresh()->started_at->toIso8601String());
        $this->assertSame(0, $contestant->refresh()->current_question);

        // The contestant vanishes. Fifteen minutes of real time pass — no
        // request, no session, no heartbeat.
        Carbon::setTestNow(Carbon::parse('2026-09-05 08:15:00'));

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        // 900 seconds elapsed / 40 = 22, exactly as the specification states.
        $this->assertSame(22, (int) floor(900 / 40), 'the arithmetic in the specification itself');
        $this->assertSame(22, $contestant->refresh()->current_question);
        $this->assertSame(23, $payload['sequence'], 'sequence is the 1-based display of index 22');

        // The live window is [08:00 + 22x40, +40) = [08:14:40, 08:15:20).
        $this->assertSame('2026-09-05T08:14:40+00:00', $payload['opened_at']);
        $this->assertSame('2026-09-05T08:15:20+00:00', $payload['expires_at']);
        $this->assertEqualsWithDelta(20.0, $payload['seconds_remaining'], 0.5);

        // The 22 positions the clock passed are spent, and none of them scored.
        $this->assertSame(str_repeat(CompetitionUser::NO_ANSWER, 22), substr((string) $contestant->answers, 0, 22));
        $this->assertSame(0, $contestant->answered_questions);
        $this->assertSame(0, $contestant->correct_answers);
    }

    /**
     * B. Window ends 11:00, begin 10:15, allowance 60 minutes.
     *
     *    personal_end  = 11:15
     *    effective_end = 11:00      ← the exam must END here
     */
    public function test_example_b_the_window_cuts_the_personal_hour_short_at_eleven(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:15:00'));

        $settings = $this->makeCompetition([
            'question_count' => 200,
            'seconds_per_question' => 40,
            'exam_duration_minutes' => 60,
            'starts_at' => Carbon::parse('2026-09-05 09:00:00'),
            'ends_at' => Carbon::parse('2026-09-05 11:00:00'),
        ]);
        $this->makeQuestions($settings, 200);
        $contestant = $this->makeContestant($settings);

        $this->exam()->startOrResume($contestant->user, $settings);
        $startedAt = $contestant->refresh()->started_at;

        $this->assertSame('2026-09-05T10:15:00+00:00', $startedAt->toIso8601String());

        // personal_end would be 11:15 …
        $this->assertSame(
            '2026-09-05T11:15:00+00:00',
            $startedAt->copy()->addMinutes(60)->toIso8601String(),
        );

        // … but effective_end is the earlier of the two.
        $this->assertSame(
            '2026-09-05T11:00:00+00:00',
            $settings->effectiveEndFor($startedAt)->toIso8601String(),
            'the contestant was granted time past the window',
        );

        // And the contestant is told, before Begin, that it is 45 minutes.
        $this->assertSame(2700, $settings->secondsAvailableFrom());

        $this->exam()->submitAnswer(
            $contestant->refresh(),
            $settings,
            null,
            $this->correctOptionAt($contestant->refresh(), 0),
        );

        // 10:59:59 — one second left, and the last window is trimmed to 11:00
        // rather than running to its own natural end.
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:59:59'));

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertNotNull($payload);
        $this->assertSame('2026-09-05T11:00:00+00:00', $payload['expires_at']);
        $this->assertEqualsWithDelta(1.0, $payload['seconds_remaining'], 0.5);

        // 11:00:00 — the exam is over: refused, AND settled as completed with
        // the answer that was submitted preserved.
        Carbon::setTestNow(Carbon::parse('2026-09-05 11:00:00'));

        try {
            $this->exam()->currentQuestion($contestant->refresh(), $settings);
            $this->fail('the exam continued past 11:00');
        } catch (ExamException) {
            // expected
        }

        $contestant->refresh();

        $this->assertTrue($contestant->isCompleted(), 'the exam did not complete at 11:00');
        $this->assertSame('2026-09-05T11:00:00+00:00', $contestant->completed_at->toIso8601String());
        $this->assertSame(1, $contestant->answered_questions);
        $this->assertSame(1, $contestant->correct_answers);
    }

    /**
     * C. The immediate-advance example, verbatim.
     *
     *    Q1 appears at 08:00:00
     *    Contestant answers at 08:00:05
     *    →  Q2 must appear immediately at 08:00:05, with up to 40 seconds.
     */
    public function test_example_c_an_early_answer_opens_the_next_question_at_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 08:00:00'));

        $settings = $this->makeCompetition([
            'question_count' => 10,
            'seconds_per_question' => 40,
            'exam_duration_minutes' => 60,
        ]);
        $this->makeQuestions($settings, 10);
        $contestant = $this->makeContestant($settings);

        $this->exam()->startOrResume($contestant->user, $settings);

        $first = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertSame(1, $first['sequence']);
        $this->assertSame('2026-09-05T08:00:00+00:00', $first['opened_at']);

        // Answered five seconds in — thirty-five seconds early.
        Carbon::setTestNow(Carbon::parse('2026-09-05 08:00:05'));
        $this->exam()->submitAnswer($contestant->refresh(), $settings, null, 'A');

        // No clock movement, no second request: Q2 is live at 08:00:05.
        $state = $this->exam()->state($contestant->refresh(), $settings);

        $this->assertNotNull($state['question'], 'the contestant was made to wait');
        $this->assertSame(2, $state['question']['sequence']);
        $this->assertSame('2026-09-05T08:00:05+00:00', $state['question']['opened_at']);
        $this->assertSame('2026-09-05T08:00:45+00:00', $state['question']['expires_at']);
        $this->assertEqualsWithDelta(40.0, $state['question']['seconds_remaining'], 0.5);
        $this->assertNotSame($first['question_id'], $state['question']['question_id']);

        // And it is DURABLE: the anchor is the moment the answer landed.
        $this->assertSame(
            '2026-09-05T08:00:05+00:00',
            $contestant->refresh()->current_question_started_at->toIso8601String(),
        );

        Carbon::setTestNow();
    }

    /**
     * D. The disconnect that follows it, verbatim.
     *
     *    Q2 starts 08:00:05, disconnect 08:00:10, return 08:02:00.
     *
     *    The backend must NOT return Q2 with 40 fresh seconds. 115 seconds have
     *    passed since the anchor: two whole windows are consumed, and the
     *    contestant rejoins 35 seconds into the third.
     */
    public function test_example_d_a_disconnect_does_not_return_the_same_question_with_a_fresh_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 08:00:00'));

        $settings = $this->makeCompetition([
            'question_count' => 10,
            'seconds_per_question' => 40,
            'exam_duration_minutes' => 60,
        ]);
        $this->makeQuestions($settings, 10);
        $contestant = $this->makeContestant($settings);

        $this->exam()->startOrResume($contestant->user, $settings);

        Carbon::setTestNow(Carbon::parse('2026-09-05 08:00:05'));
        $this->exam()->submitAnswer($contestant->refresh(), $settings, null, 'A');

        $secondQuestionId = $this->exam()->currentQuestion($contestant->refresh(), $settings)['question_id'];

        // Off at 08:00:10, back at 08:02:00. 115 seconds / 40 = 2 windows.
        Carbon::setTestNow(Carbon::parse('2026-09-05 08:02:00'));

        $payload = $this->exam()->currentQuestion($contestant->refresh(), $settings);

        $this->assertNotSame($secondQuestionId, $payload['question_id'], 'Q2 came back after its window closed');
        $this->assertSame(3, (int) $contestant->refresh()->current_question);
        $this->assertSame(4, $payload['sequence']);
        $this->assertSame('2026-09-05T08:01:25+00:00', $payload['opened_at'], '08:00:05 + 2x40');
        $this->assertSame('2026-09-05T08:02:05+00:00', $payload['expires_at']);
        $this->assertEqualsWithDelta(5.0, $payload['seconds_remaining'], 0.5);

        // The two windows that ran out are spent, and scored nothing.
        $this->assertSame('--', substr((string) $contestant->refresh()->answers, 1, 2));
        $this->assertSame(1, $contestant->refresh()->answered_questions);

        Carbon::setTestNow();
    }

    /**
     * The control for C: advancing immediately must not COST the contestant a
     * question either. A test that only checked "the next question came at
     * once" would also pass if the engine had thrown a position away.
     */
    public function test_example_c_control_every_position_is_still_answerable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00'));

        $settings = $this->makeCompetition([
            'question_count' => 10,
            'seconds_per_question' => 40,
            'exam_duration_minutes' => 60,
        ]);
        $this->makeQuestions($settings, 10);
        $contestant = $this->makeContestant($settings);

        $this->exam()->startOrResume($contestant->user, $settings);

        // Ten questions answered correctly, one second apart. Ten seconds of
        // wall clock, a complete paper, and nothing skipped.
        for ($position = 0; $position < 10; $position++) {
            Carbon::setTestNow(Carbon::parse('2026-09-05 09:00:00')->addSeconds($position + 1));

            $this->exam()->submitAnswer(
                $contestant->refresh(),
                $settings,
                null,
                $this->correctOptionAt($contestant->refresh(), $position),
            );
        }

        $contestant->refresh();

        $this->assertSame(10, $contestant->answered_questions, 'a position was lost');
        $this->assertSame(10, $contestant->correct_answers);
        $this->assertTrue($contestant->isCompleted());

        Carbon::setTestNow();
    }
}
