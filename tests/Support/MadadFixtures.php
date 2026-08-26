<?php

namespace Tests\Support;

use App\Models\CompetitionQuestion;
use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Models\User;
use App\Services\Competition\QuestionOrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Builders for the test database.
 *
 * Nothing here hard-codes an id: every helper returns the model it created and
 * tests refer to that, so the development fixtures in madad_dev can be
 * reseeded, renumbered or dropped without touching a single test.
 *
 * `makeCompetition()` UPDATES the settings singleton rather than creating a
 * competition — there is only ever one, and the migration already made it.
 */
trait MadadFixtures
{
    /**
     * The competition's configuration, with test defaults.
     *
     * The availability window is left wide open by default so that tests about
     * the exam are not accidentally tests about the window. Tests that care
     * pass `starts_at` / `ends_at` explicitly.
     */
    protected function makeCompetition(array $overrides = []): CompetitionSettings
    {
        $settings = CompetitionSettings::current();

        $settings->forceFill($overrides + [
            'name' => 'Madad Phase 1 (test)',
            'status' => CompetitionSettings::STATUS_OPEN,
            'show_result' => false,
            'question_count' => 5,
            'seconds_per_question' => 40,
            'exam_duration_minutes' => 60,
            'starts_at' => null,
            'ends_at' => null,
        ])->save();

        return $settings;
    }

    /** @return array<int, CompetitionQuestion> */
    protected function makeQuestions(CompetitionSettings $settings, int $count = 5): array
    {
        $letters = ['A', 'B', 'C', 'D'];
        $questions = [];

        for ($n = 1; $n <= $count; $n++) {
            $questions[] = CompetitionQuestion::query()->create([
                'question_number' => $n,
                'question_text' => "سؤال رقم {$n}",
                'option_a' => "خيار أ {$n}",
                'option_b' => "خيار ب {$n}",
                'option_c' => "خيار ج {$n}",
                'option_d' => "خيار د {$n}",
                'correct_option' => $letters[$n % 4],
            ]);
        }

        return $questions;
    }

    protected function makeContestant(CompetitionSettings $settings, array $overrides = []): CompetitionUser
    {
        $suffix = CompetitionUser::query()->count() + 1;
        $email = $overrides['contestant_email'] ?? "contestant{$suffix}@madad.test";

        $user = null;

        if (($overrides['account_status'] ?? CompetitionUser::ACCOUNT_CREATED) === CompetitionUser::ACCOUNT_CREATED) {
            $user = User::query()->create([
                'name' => "متسابق {$suffix}",
                'email' => $email,
                'password' => Hash::make('secret-password'),
            ]);
        }

        return CompetitionUser::query()->create($overrides + [
            'user_id' => $user?->id,
            'contestant_name' => "متسابق {$suffix}",
            'contestant_email' => $email,
            'account_status' => CompetitionUser::ACCOUNT_CREATED,
            'email_status' => CompetitionUser::EMAIL_SENT,
            'email_attempts' => 1,
            'exam_status' => CompetitionUser::EXAM_NOT_STARTED,
            'correct_answers' => 0,
            'answered_questions' => 0,
        ]);
    }

    /** An unprovisioned participation: no account, no user row. */
    protected function makeUnprovisionedContestant(CompetitionSettings $settings): CompetitionUser
    {
        $suffix = CompetitionUser::query()->count() + 1;

        return CompetitionUser::query()->create([
            'user_id' => null,
            'contestant_name' => "مرشح {$suffix}",
            'contestant_email' => "pending{$suffix}@madad.test",
            'account_status' => CompetitionUser::ACCOUNT_PENDING,
            'email_status' => CompetitionUser::EMAIL_PENDING,
            'email_attempts' => 0,
            'exam_status' => CompetitionUser::EXAM_NOT_STARTED,
            'correct_answers' => 0,
            'answered_questions' => 0,
        ]);
    }

    /**
     * Give a contestant a persisted question order without starting the clock.
     *
     * @return list<int> the order that was persisted
     */
    protected function giveOrder(CompetitionUser $contestant, CompetitionSettings $settings): array
    {
        $order = app(QuestionOrderService::class)->build($settings);

        $contestant->forceFill([
            'question_order' => $order,
            'answers' => str_repeat(CompetitionUser::NO_ANSWER, count($order)),
            'current_question' => 0,
        ])->save();

        return $order;
    }

    /**
     * Put a contestant at a position on a timeline of our choosing.
     *
     * There is only one anchor to set. By default `started_at` is placed
     * exactly `$index` slots in the past, which is where the fixed timeline
     * puts a contestant sitting at that position — so the fixture and the
     * engine agree by construction rather than by coincidence.
     */
    protected function placeAt(
        CompetitionUser $contestant,
        CompetitionSettings $settings,
        int $index,
        ?Carbon $startedAt = null,
    ): CompetitionUser {
        if ($contestant->order() === []) {
            $this->giveOrder($contestant, $settings);
        }

        $startedAt ??= now()->subSeconds($index * $settings->secondsPerQuestion());

        $contestant->forceFill([
            'exam_status' => CompetitionUser::EXAM_IN_PROGRESS,
            'started_at' => $startedAt,
            'current_question' => $index,
        ])->save();

        return $contestant->refresh();
    }

    /**
     * Move the server clock into the slot that owns $index.
     *
     * Under the fixed timeline a contestant cannot answer position N until
     * started_at + N·s, so a test that answers several questions in a row has
     * to let the clock reach each slot. `$offset` is how far into the slot to
     * land — 2 seconds by default, comfortably inside a 40-second window.
     */
    protected function enterSlot(
        CompetitionUser $contestant,
        CompetitionSettings $settings,
        int $index,
        int $offset = 2,
    ): void {
        $startedAt = $contestant->refresh()->started_at;

        $this->travelTo(
            $startedAt->copy()->addSeconds($index * $settings->secondsPerQuestion() + $offset)
        );
    }

    /** The option that would be marked correct at a position on this paper. */
    protected function correctOptionAt(CompetitionUser $contestant, int $index): string
    {
        return CompetitionQuestion::query()
            ->findOrFail($contestant->questionIdAt($index))
            ->correct_option;
    }

    /** An option that would be marked wrong at a position on this paper. */
    protected function wrongOptionAt(CompetitionUser $contestant, int $index): string
    {
        $correct = $this->correctOptionAt($contestant, $index);

        return array_values(array_diff(CompetitionQuestion::OPTIONS, [$correct]))[0];
    }
}
