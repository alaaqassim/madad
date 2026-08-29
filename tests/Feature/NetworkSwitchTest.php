<?php

namespace Tests\Feature;

use App\Models\CompetitionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * A contestant whose network changes underneath them mid-exam.
 *
 * The hall's wifi drops and the phone falls back to mobile data, or they walk
 * out of range, or the router hands out a new lease. The address the server
 * sees changes; nothing about the contestant does. On competition day this will
 * happen to somebody, so it is proved here rather than hoped for.
 *
 * Two things could have made it fatal, and neither does. The session is not
 * bound to an address, so the cookie still authenticates. And the exam lives
 * entirely on the contestant's row, so the new connection reads the same
 * position the old one left.
 *
 * What the switch does NOT buy is time: the clock is the server's, and the
 * seconds spent reconnecting are spent.
 */
class NetworkSwitchTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private const PASSWORD = 'contestant-password';

    private const WIFI = '192.168.1.40';

    private const MOBILE = '5.62.61.7';

    private function contestant(int $questions = 5): CompetitionUser
    {
        $competition = $this->makeCompetition([
            'question_count' => $questions,
            'seconds_per_question' => 40,
            'show_result' => true,
        ]);
        $this->makeQuestions($competition, $questions);

        $participation = $this->makeContestant($competition);
        $participation->user->forceFill(['password' => Hash::make(self::PASSWORD)])->save();

        return $participation;
    }

    /** Every following request in the test arrives from this address. */
    private function fromAddress(string $address): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $address]);
    }

    public function test_a_contestant_who_switches_network_mid_exam_keeps_their_session_and_their_place(): void
    {
        $this->freezeTime();

        $participation = $this->contestant(5);

        // ── on the hall wifi ────────────────────────────────────────────────
        $this->fromAddress(self::WIFI);

        $this->postJson('/api/login', [
            'email' => $participation->contestant_email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->postJson('/api/exam/start')->assertOk();

        $participation->refresh();
        $first = $participation->order()[0];

        $this->postJson('/api/exam/answer', [
            'question_id' => $first,
            'selected_option' => $this->correctOptionAt($participation, 0),
        ])->assertOk();

        // ── the wifi drops, the phone falls back to mobile data ─────────────
        $this->fromAddress(self::MOBILE);

        $current = $this->getJson('/api/exam/current')
            ->assertOk()
            ->assertJsonPath('exam_status', CompetitionUser::EXAM_IN_PROGRESS);

        $participation->refresh();

        $this->assertSame(1, (int) $participation->current_question, 'the new connection lost the contestant position');
        $this->assertSame(
            $participation->order()[1],
            $current->json('question.question_id'),
            'the second question was not the one served after the switch',
        );

        // And they can still answer — the session survived the address change.
        $this->postJson('/api/exam/answer', [
            'question_id' => $participation->order()[1],
            'selected_option' => $this->correctOptionAt($participation, 1),
        ])->assertOk();

        $participation->refresh();

        $this->assertSame(2, (int) $participation->correct_answers, 'the answer sent over the new network was not counted');
        $this->assertSame(2, (int) $participation->answered_questions);
    }

    public function test_the_address_change_does_not_reset_the_answer_that_was_already_recorded(): void
    {
        $this->freezeTime();

        $participation = $this->contestant(5);

        $this->fromAddress(self::WIFI);

        $this->postJson('/api/login', [
            'email' => $participation->contestant_email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->postJson('/api/exam/start')->assertOk();

        $participation->refresh();

        $this->postJson('/api/exam/answer', [
            'question_id' => $participation->order()[0],
            'selected_option' => $this->correctOptionAt($participation, 0),
        ])->assertOk();

        $before = $participation->refresh()->answers;

        $this->fromAddress(self::MOBILE);
        $this->getJson('/api/exam/current')->assertOk();

        $this->assertSame($before, $participation->refresh()->answers, 'reconnecting on another network rewrote recorded answers');
    }

    public function test_a_switch_that_takes_longer_than_a_question_costs_that_question(): void
    {
        $this->freezeTime();

        $participation = $this->contestant(5);

        $this->fromAddress(self::WIFI);

        $this->postJson('/api/login', [
            'email' => $participation->contestant_email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->postJson('/api/exam/start')->assertOk();

        $participation->refresh();

        // 45 seconds off the network: longer than the 40 the live question had.
        $this->travel(45)->seconds();

        $this->fromAddress(self::MOBILE);

        $this->getJson('/api/exam/current')->assertOk();

        $participation->refresh();

        $this->assertSame(1, (int) $participation->current_question, 'the missed question was not consumed');
        $this->assertSame(
            CompetitionUser::NO_ANSWER,
            $participation->answers[0],
            'the question that expired during the switch was not marked unanswered',
        );
        $this->assertSame(0, (int) $participation->answered_questions, 'a question nobody answered was counted as answered');
    }

    public function test_the_new_address_starts_with_a_clean_login_counter(): void
    {
        // The login limiter is keyed by email AND address, so a contestant who
        // fumbled their password on the wifi is not still serving that penalty
        // when their phone comes back on a different network. The pairing is
        // also what stops one hall's address from locking out the whole roster.
        $participation = $this->contestant(5);

        $this->fromAddress(self::WIFI);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', [
                'email' => $participation->contestant_email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => $participation->contestant_email,
            'password' => self::PASSWORD,
        ])->assertStatus(422)->assertJsonPath('reason', 'too_many_attempts');

        // Same contestant, new network, correct password: served.
        $this->fromAddress(self::MOBILE);

        $this->postJson('/api/login', [
            'email' => $participation->contestant_email,
            'password' => self::PASSWORD,
        ])->assertOk();
    }
}
