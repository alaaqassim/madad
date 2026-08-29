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
            // Delivered: a non-null credentials_sent_at IS `sent`.
            'credentials_sent_at' => now(),
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
            // Never attempted: no send time and no attempts IS `pending`.
            'credentials_sent_at' => null,
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
     * Put a contestant at a position, with both anchors set explicitly.
     *
     * TWO anchors, because the engine has two. `started_at` bounds the attempt;
     * `$questionStartedAt` is when the LIVE question became live, and under
     * immediate advance it is NOT derivable from the index — a fast contestant
     * reaches position 4 in seconds, a slow one in minutes.
     *
     * The defaults describe the plainest case: an attempt that began $index
     * windows ago and a question that opened just now, which is what a
     * contestant who has answered steadily and is looking at a fresh question
     * looks like.
     */
    protected function placeAt(
        CompetitionUser $contestant,
        CompetitionSettings $settings,
        int $index,
        ?Carbon $startedAt = null,
        ?Carbon $questionStartedAt = null,
    ): CompetitionUser {
        if ($contestant->order() === []) {
            $this->giveOrder($contestant, $settings);
        }

        $startedAt ??= now()->subSeconds($index * $settings->secondsPerQuestion());

        $contestant->forceFill([
            'exam_status' => CompetitionUser::EXAM_IN_PROGRESS,
            'started_at' => $startedAt,
            'current_question' => $index,
            'current_question_started_at' => $questionStartedAt ?? now(),
        ])->save();

        return $contestant->refresh();
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

    /**
     * A wall-clock time, as the API would render it.
     *
     * The hour is the assertion; the offset that follows it is not. Writing the
     * offset out by hand made a change of the application's timezone look like
     * twenty-eight broken tests, when every one of them held the right hour and
     * only disagreed about the suffix. So the hour stays visible and the offset
     * follows the configuration.
     */
    protected function iso(string $wallClock): string
    {
        return Carbon::parse($wallClock, config('app.timezone'))->toIso8601String();
    }
}
