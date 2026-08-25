<?php

namespace App\Services\Competition;

use App\Exceptions\ExamException;
use App\Models\CompetitionQuestion;
use App\Models\CompetitionUser;
use App\Models\CompetitionUserQuestion;
use Illuminate\Support\Facades\DB;

/**
 * Builds a contestant's paper: their own randomised order, persisted once.
 *
 * Called only from inside a transaction that already holds a row lock on the
 * participation (see CompetitionExamService::startOrResume), which is what
 * makes duplicate start requests harmless. The unique constraints on
 * (competition_user_id, sequence) and (competition_user_id, competition_question_id)
 * are the last line of defence behind that lock.
 */
class PaperService
{
    /**
     * Idempotent. If a paper already exists it is left exactly as it is —
     * a refresh, a re-login or a second start request must never reshuffle.
     *
     * @return int the number of questions on the paper
     */
    public function ensurePaper(CompetitionUser $participation): int
    {
        $existing = CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)
            ->count();

        if ($existing > 0) {
            return $existing;
        }

        $competition = $participation->competition()->firstOrFail();

        $questionIds = CompetitionQuestion::query()
            ->where('competition_id', $competition->id)
            ->orderBy('question_number')
            ->pluck('id')
            ->all();

        // Refuse rather than hand out a short paper: a contestant sitting 60 of
        // 75 questions would be silently disadvantaged against the field.
        if (count($questionIds) < $competition->question_count) {
            throw ExamException::paperNotReady();
        }

        shuffle($questionIds);
        $selected = array_slice($questionIds, 0, $competition->question_count);

        $now = now();
        $rows = [];

        foreach ($selected as $index => $questionId) {
            $rows[] = [
                'competition_user_id' => $participation->id,
                'competition_question_id' => $questionId,
                'sequence' => $index + 1,
                'opened_at' => null,
                'expires_at' => null,
                'selected_option' => null,
                'answered_at' => null,
                'is_correct' => false,
                'timed_out' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('competition_user_questions')->insert($rows);

        return count($rows);
    }
}
