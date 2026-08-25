<?php

namespace Tests\Support;

use App\Models\Competition;
use App\Models\CompetitionQuestion;
use App\Models\CompetitionUser;
use App\Models\User;
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
}
