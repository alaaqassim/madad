<?php

namespace Tests\Support;

use App\Models\Competition;
use App\Models\CompetitionQuestion;
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
 */
trait MadadFixtures
{
    protected function makeCompetition(array $overrides = []): Competition
    {
        return Competition::query()->create($overrides + [
            'name' => 'Madad Phase 1 (test)',
            'status' => Competition::STATUS_OPEN,
            'show_result' => false,
            'question_count' => 5,
            'seconds_per_question' => 40,
        ]);
    }

    /** @return array<int, CompetitionQuestion> */
    protected function makeQuestions(Competition $competition, int $count = 5): array
    {
        $letters = ['A', 'B', 'C', 'D'];
        $questions = [];

        for ($n = 1; $n <= $count; $n++) {
            $questions[] = CompetitionQuestion::query()->create([
                'competition_id' => $competition->id,
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

    protected function makeContestant(Competition $competition, array $overrides = []): CompetitionUser
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
            'competition_id' => $competition->id,
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
    protected function makeUnprovisionedContestant(Competition $competition): CompetitionUser
    {
        $suffix = CompetitionUser::query()->count() + 1;

        return CompetitionUser::query()->create([
            'competition_id' => $competition->id,
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
    protected function giveOrder(CompetitionUser $contestant, Competition $competition): array
    {
        $order = app(QuestionOrderService::class)->build($competition);

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
     * `$startedAt` anchors the whole exam; `$arrivedAt` is when they reached
     * $index. The defaults place them on the fixed grid, exactly where a
     * contestant who used every full window would be.
     */
    protected function placeAt(
        CompetitionUser $contestant,
        Competition $competition,
        int $index,
        ?Carbon $startedAt = null,
        ?Carbon $arrivedAt = null,
    ): CompetitionUser {
        if ($contestant->order() === []) {
            $this->giveOrder($contestant, $competition);
        }

        $startedAt ??= now()->subSeconds($index * $competition->seconds_per_question);
        $arrivedAt ??= $startedAt->copy()->addSeconds($index * $competition->seconds_per_question);

        $contestant->forceFill([
            'exam_status' => CompetitionUser::EXAM_IN_PROGRESS,
            'started_at' => $startedAt,
            'current_question' => $index,
            'current_question_started_at' => $arrivedAt,
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
}
