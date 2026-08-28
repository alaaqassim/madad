<?php

namespace Tests\Feature;

use App\Exceptions\ExamException;
use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * Every state a contestant can be in, simulated end to end.
 *
 * The rest of the suite is organised by mechanism - the timeline here, answer
 * submission there, settlement somewhere else - which is right for finding a
 * broken rule but wrong for answering "is every situation a student can land in
 * actually handled?". Nobody can read those files and tell.
 *
 * So this one is organised by the student instead. Each test puts a contestant
 * into one situation and asserts what they are told, through the real
 * endpoints. Read top to bottom it is the whole life of a contestant: shut out,
 * let in, answering, disconnected, out of time, finished.
 *
 * Overlap with other files is deliberate. A rule proved once in isolation is
 * still worth showing in the situation a student meets it in.
 */
class ContestantStatesTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    /**
     * Which test covers which refusal.
     *
     * The last test in this file walks this map, so a new reason added to
     * ExamException fails the suite until somebody writes the situation that
     * produces it. That is what makes "every state" checkable rather than a
     * claim in a comment.
     *
     * @var array<string, string>
     */
    private const COVERED = [
        'competition_not_open' => 'test_a_competition_still_being_prepared_turns_the_contestant_away',
        'competition_closed' => 'test_a_closed_competition_is_final',
        'not_a_contestant' => 'test_somebody_who_is_not_on_the_roster_is_turned_away',
        'account_not_provisioned' => 'test_a_contestant_whose_account_failed_to_provision_is_turned_away',
        'exam_completed' => 'test_a_finished_exam_cannot_be_reopened',
        'question_not_available' => 'test_answering_a_question_that_is_not_theirs_is_refused',
        'question_expired' => 'test_a_position_they_were_carried_past_is_reported_as_expired',
        'paper_not_ready' => 'test_a_competition_with_too_few_questions_refuses_to_start',

        // Not reachable through the API. payloadFor() raises it when an index
        // has no question behind it, and every caller checks isCompleted()
        // first, so it guards against a future caller rather than a contestant.
        'no_current_question' => '',
    ];

    private const PASSWORD = 'contestant-password';

    /** A provisioned contestant on an open competition with a full bank. */
    private function contestant(array $competition = [], int $questions = 5): CompetitionUser
    {
        $settings = $this->makeCompetition($competition + ['question_count' => $questions]);
        $this->makeQuestions($settings, $questions);

        return $this->makeContestant($settings);
    }

    /** Signs in and begins, returning the first question. */
    private function begin(CompetitionUser $participation): array
    {
        $this->actingAs($participation->user);

        return $this->postJson('/api/exam/start')->assertOk()->json('question');
    }

    /** Answers whatever question is live, and returns the next one or null. */
    private function answer(int $questionId, string $option = 'A'): ?array
    {
        return $this->postJson('/api/exam/answer', [
            'question_id' => $questionId,
            'selected_option' => $option,
        ])->assertOk()->json('next_question');
    }

    private function assertRefused(TestResponse $response, string $reason, int $status): void
    {
        $response->assertStatus($status);

        $this->assertSame($reason, $response->json('reason'), 'the contestant was given the wrong reason');
    }

    // ── Before the gate ─────────────────────────────────────────────────────

    public function test_there_is_no_competition_at_all(): void
    {
        DB::table('competition_settings')->delete();

        $response = $this->getJson('/api/competition/status')->assertOk();

        $this->assertSame('no_competition', $response->json('reason'));
        $this->assertFalse($response->json('open'));
    }

    public function test_a_competition_still_being_prepared_turns_the_contestant_away(): void
    {
        // Both pre-open states answer alike: the operator has not thrown the
        // switch yet, and waiting may help.
        $participation = $this->contestant();
        $settings = CompetitionSettings::current();

        foreach ([CompetitionSettings::STATUS_DRAFT, CompetitionSettings::STATUS_READY] as $status) {
            $settings->forceFill(['status' => $status])->save();

            $this->getJson('/api/competition/status')
                ->assertOk()
                ->assertJsonPath('open', false)
                ->assertJsonPath('reason', 'competition_not_open');

            $this->actingAs($participation->user);
            $this->assertRefused($this->postJson('/api/exam/start'), 'competition_not_open', 403);
        }
    }

    public function test_the_competition_has_not_opened_yet(): void
    {
        // The switch is on but the clock has not reached the announced start.
        // A retry later can succeed, so this is not the terminal refusal.
        $participation = $this->contestant(['starts_at' => now()->addHour()]);

        $this->actingAs($participation->user);
        $this->assertRefused($this->postJson('/api/exam/start'), 'competition_not_open', 403);
    }

    public function test_a_closed_competition_is_final(): void
    {
        $participation = $this->contestant(['status' => CompetitionSettings::STATUS_CLOSED]);

        $this->getJson('/api/competition/status')
            ->assertOk()
            ->assertJsonPath('reason', 'competition_closed');

        $this->actingAs($participation->user);
        $this->assertRefused($this->postJson('/api/exam/start'), 'competition_closed', 403);
    }

    public function test_the_window_has_passed(): void
    {
        // Terminal for the same reason a closed switch is: no later request can
        // succeed, so the client must not offer a retry.
        $participation = $this->contestant(['ends_at' => now()->subMinute()]);

        $this->actingAs($participation->user);
        $this->assertRefused($this->postJson('/api/exam/start'), 'competition_closed', 403);
    }

    public function test_somebody_who_is_not_on_the_roster_is_turned_away(): void
    {
        $this->contestant();

        $stranger = User::query()->create([
            'name' => 'زائر',
            'email' => 'stranger@madad.test',
            'password' => bcrypt(self::PASSWORD),
        ]);

        $this->actingAs($stranger);
        $this->assertRefused($this->postJson('/api/exam/start'), 'not_a_contestant', 403);
    }

    public function test_a_contestant_whose_account_failed_to_provision_is_turned_away(): void
    {
        // They can sign in - the account exists - but their participation was
        // never activated, so the exam is not theirs to begin.
        $participation = $this->contestant();
        $participation->forceFill(['account_status' => CompetitionUser::ACCOUNT_FAILED])->save();

        $this->actingAs($participation->user);
        $this->assertRefused($this->postJson('/api/exam/start'), 'account_not_provisioned', 403);
    }

    public function test_a_competition_with_too_few_questions_refuses_to_start(): void
    {
        // Asking for five when three exist. Serving a short paper would be
        // worse than refusing: the contestant would be scored out of five.
        $participation = $this->contestant(questions: 3);
        CompetitionSettings::current()->forceFill(['question_count' => 5])->save();

        $this->actingAs($participation->user);
        $this->assertRefused($this->postJson('/api/exam/start'), 'paper_not_ready', 409);
    }

    // ── Inside the exam ─────────────────────────────────────────────────────

    public function test_a_contestant_who_has_not_begun_sees_the_portal_open(): void
    {
        $participation = $this->contestant();

        $this->actingAs($participation->user);

        $this->getJson('/api/competition/status')
            ->assertOk()
            ->assertJsonPath('open', true)
            ->assertJsonPath('participation.exam_status', CompetitionUser::EXAM_NOT_STARTED);
    }

    public function test_beginning_serves_the_first_question(): void
    {
        $this->freezeTime();

        $question = $this->begin($this->contestant());

        $this->assertSame(1, $question['sequence']);
        $this->assertSame(5, $question['total_questions']);
        $this->assertSame(40.0, round($question['seconds_remaining']));
        $this->assertSame(['A', 'B', 'C', 'D'], array_keys($question['options']));

        // Nothing that could be used as an answer key.
        $this->assertArrayNotHasKey('correct_option', $question);
        $this->assertArrayNotHasKey('is_correct', $question);
    }

    public function test_answering_serves_the_next_question_immediately(): void
    {
        $this->freezeTime();

        $first = $this->begin($this->contestant());

        // Answered after five seconds; the next question must arrive with a
        // fresh window, not the thirty-five seconds left of the old one.
        $this->travel(5)->seconds();
        $next = $this->answer($first['question_id']);

        $this->assertSame(2, $next['sequence']);
        $this->assertNotSame($first['question_id'], $next['question_id']);
        $this->assertSame(40.0, round($next['seconds_remaining']));
    }

    public function test_a_contestant_who_reloads_sees_the_same_question_with_less_time(): void
    {
        $this->freezeTime();

        $first = $this->begin($this->contestant());
        $this->travel(15)->seconds();

        $again = $this->getJson('/api/exam/current')->assertOk()->json('question');

        $this->assertSame($first['question_id'], $again['question_id']);
        $this->assertSame(25.0, round($again['seconds_remaining']));
    }

    public function test_a_contestant_who_disconnects_is_carried_forward_not_paused(): void
    {
        $this->freezeTime();

        $participation = $this->contestant();
        $this->begin($participation);

        // Away for two whole windows and a little more.
        $this->travel(95)->seconds();

        $question = $this->getJson('/api/exam/current')->assertOk()->json('question');

        // Two windows were consumed, so they return to the third question with
        // the remainder of its window - not to a fresh forty seconds.
        $this->assertSame(3, $question['sequence']);
        $this->assertSame(25.0, round($question['seconds_remaining']));

        $participation->refresh();
        $this->assertSame('--', substr($participation->answers, 0, 2), 'the skipped positions were not left blank');
    }

    public function test_answering_a_question_that_is_not_theirs_is_refused(): void
    {
        $participation = $this->contestant();
        $this->begin($participation);

        // A question that exists but is not at their current position. The
        // refusal is deliberately the same one a nonexistent id gets.
        $notTheirs = $participation->fresh()->questionIdAt(3);

        $this->assertRefused($this->postJson('/api/exam/answer', [
            'question_id' => $notTheirs,
            'selected_option' => 'A',
        ]), 'question_not_available', 422);
    }

    public function test_a_position_they_were_carried_past_is_reported_as_expired(): void
    {
        $this->freezeTime();

        $participation = $this->contestant();
        $first = $this->begin($participation);

        // The window closes under them, and they answer the question they were
        // still looking at. This is the one refusal that says "you lost
        // something" rather than "that is not available".
        $this->travel(45)->seconds();

        $this->assertRefused($this->postJson('/api/exam/answer', [
            'question_id' => $first['question_id'],
            'selected_option' => 'A',
        ]), 'question_expired', 422);
    }

    public function test_an_option_outside_a_b_c_d_is_rejected_before_anything_is_recorded(): void
    {
        $participation = $this->contestant();
        $first = $this->begin($participation);

        $this->postJson('/api/exam/answer', [
            'question_id' => $first['question_id'],
            'selected_option' => 'E',
        ])->assertStatus(422)->assertJsonValidationErrors('selected_option');

        $participation->refresh();
        $this->assertSame(0, (int) $participation->current_question, 'a rejected option still moved the contestant');
        $this->assertSame(0, $participation->answered_questions);
    }

    // ── How an exam ends ────────────────────────────────────────────────────

    public function test_answering_the_last_question_completes_the_exam(): void
    {
        $this->freezeTime();

        $participation = $this->contestant(questions: 3);
        CompetitionSettings::current()->forceFill(['question_count' => 3])->save();

        $question = $this->begin($participation);

        for ($i = 0; $i < 3; $i++) {
            $this->assertNotNull($question, "the exam ended early, at question {$i}");
            $question = $this->answer($question['question_id']);
        }

        $this->assertNull($question, 'a fourth question was served for a three question paper');

        $participation->refresh();
        $this->assertTrue($participation->isCompleted());
        $this->assertNotNull($participation->completed_at);
        $this->assertSame(3, $participation->answered_questions);
    }

    public function test_letting_every_window_pass_completes_the_exam(): void
    {
        $this->freezeTime();

        $participation = $this->contestant();
        $this->begin($participation);

        // Five questions at forty seconds is two hundred seconds of paper. The
        // contestant never comes back inside it.
        $this->travel(201)->seconds();

        $this->getJson('/api/exam/current')->assertOk()->assertJsonPath('question', null);

        $participation->refresh();
        $this->assertTrue($participation->isCompleted());
        $this->assertSame(0, $participation->answered_questions);
        $this->assertSame(str_repeat('-', 5), $participation->answers);
    }

    public function test_the_personal_allowance_can_end_the_exam_before_the_paper_does(): void
    {
        $this->freezeTime();

        // A one minute allowance against a two hundred second paper: the
        // allowance is what runs out first.
        $participation = $this->contestant(['exam_duration_minutes' => 1]);
        $this->begin($participation);

        $this->travel(61)->seconds();

        $this->getJson('/api/exam/current')->assertOk()->assertJsonPath('question', null);

        $participation->refresh();
        $this->assertTrue($participation->isCompleted());
        $this->assertSame(60, (int) round($participation->started_at->diffInSeconds($participation->completed_at)));
    }

    public function test_the_window_closing_while_they_are_away_still_completes_them(): void
    {
        $this->freezeTime();

        $participation = $this->contestant(['ends_at' => now()->addSeconds(30)]);
        $this->begin($participation);

        // They disconnect, and the competition ends without them. Every exam
        // endpoint now refuses - but the result surface still settles them,
        // which is the only reason they appear in the ranking at all.
        $this->travel(120)->seconds();

        $this->assertRefused($this->getJson('/api/exam/current'), 'competition_closed', 403);

        $this->getJson('/api/exam/result')
            ->assertOk()
            ->assertJsonPath('exam_status', CompetitionUser::EXAM_COMPLETED);

        $participation->refresh();
        $this->assertTrue($participation->isCompleted());

        // Stamped at the window's end, not at the moment anybody noticed. Both
        // sides are read back from the database so the comparison does not turn
        // into one about column precision.
        $this->assertSame(
            CompetitionSettings::current()->ends_at->getTimestamp(),
            $participation->completed_at->getTimestamp(),
            'the exam was not stamped at the moment the competition ended',
        );
    }

    public function test_a_finished_exam_cannot_be_reopened(): void
    {
        $this->freezeTime();

        $participation = $this->contestant(questions: 1);
        CompetitionSettings::current()->forceFill(['question_count' => 1])->save();

        $question = $this->begin($participation);
        $this->answer($question['question_id']);

        $before = $participation->fresh()->completed_at;

        /*
         * Begin does not error here, and should not.
         *
         * A contestant who refreshes after finishing is not making a mistake,
         * and an error page would be a worse answer than the truth. So Begin is
         * idempotent on a finished exam: it re-reports the finished state with
         * no question, which is exactly what the completion screen renders.
         */
        $this->postJson('/api/exam/start')
            ->assertOk()
            ->assertJsonPath('exam_status', CompetitionUser::EXAM_COMPLETED)
            ->assertJsonPath('question', null);

        // Answering is different: it asks to change something that is finished.
        $this->assertRefused($this->postJson('/api/exam/answer', [
            'question_id' => $question['question_id'],
            'selected_option' => 'B',
        ]), 'exam_completed', 409);

        $this->assertEquals($before, $participation->fresh()->completed_at, 'a refused retry moved the finish line');
    }

    // ── What they are told afterwards ───────────────────────────────────────

    public function test_the_result_is_withheld_when_the_competition_says_so(): void
    {
        $this->freezeTime();

        $participation = $this->contestant(['show_result' => false], questions: 1);
        CompetitionSettings::current()->forceFill(['question_count' => 1])->save();

        $this->answer($this->begin($participation)['question_id']);

        $result = $this->getJson('/api/exam/result')->assertOk();

        $result->assertJsonPath('exam_status', CompetitionUser::EXAM_COMPLETED)
            ->assertJsonPath('show_result', false);

        // Not merely hidden in the client: absent from the response.
        $this->assertArrayNotHasKey('correct_answers', $result->json());
        $this->assertArrayNotHasKey('total_questions', $result->json());
    }

    public function test_the_result_is_shown_when_the_competition_says_so(): void
    {
        $this->freezeTime();

        $participation = $this->contestant(['show_result' => true], questions: 3);
        CompetitionSettings::current()->forceFill(['question_count' => 3])->save();

        $question = $this->begin($participation);

        for ($i = 0; $i < 3; $i++) {
            $question = $this->answer($question['question_id'], 'A');
        }

        $participation->refresh();

        $this->getJson('/api/exam/result')
            ->assertOk()
            ->assertJsonPath('show_result', true)
            ->assertJsonPath('answered_questions', 3)
            ->assertJsonPath('total_questions', 3)
            ->assertJsonPath('correct_answers', $participation->correct_answers);
    }

    public function test_a_contestant_who_never_began_has_no_result_to_read(): void
    {
        $participation = $this->contestant();

        $this->actingAs($participation->user);

        $this->getJson('/api/exam/result')
            ->assertOk()
            ->assertJsonPath('exam_status', CompetitionUser::EXAM_NOT_STARTED)
            ->assertJsonPath('completed_at', null);
    }

    // ── The guard on this file ──────────────────────────────────────────────

    public function test_every_refusal_the_contestant_can_meet_is_simulated_here(): void
    {
        foreach (ExamException::REASONS as $reason) {
            $this->assertArrayHasKey(
                $reason,
                self::COVERED,
                "ExamException can answer '{$reason}' and no test in this file puts a contestant in that situation",
            );

            $test = self::COVERED[$reason];

            if ($test === '') {
                continue;   // Documented as unreachable through the API.
            }

            $this->assertTrue(
                method_exists($this, $test),
                "'{$reason}' claims to be covered by {$test}(), which does not exist",
            );
        }
    }
}
