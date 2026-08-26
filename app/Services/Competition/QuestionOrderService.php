<?php

namespace App\Services\Competition;

use App\Exceptions\ExamException;
use App\Models\CompetitionQuestion;
use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;

/**
 * Builds and guards a contestant's question order.
 *
 * The order is randomised once and persisted on the participation row. It is
 * never regenerated: a refresh, a re-login, a second start request, or the same
 * contestant arriving from another device all read the array that is already
 * there. That is the whole safety property — a contestant who could reshuffle
 * could farm easy questions.
 *
 * Called only from inside a transaction that already holds a row lock on the
 * participation, which is what makes concurrent start requests harmless.
 */
class QuestionOrderService
{
    /**
     * The contestant's paper, generated on first call and stable thereafter.
     *
     * @return list<int> competition_questions ids, in this contestant's order
     */
    public function ensureOrder(CompetitionUser $participation, CompetitionSettings $settings): array
    {
        $existing = $participation->order();

        if ($existing !== []) {
            return $existing;
        }

        $order = $this->build($settings);

        $participation->forceFill([
            'question_order' => $order,
            'answers' => str_repeat(CompetitionUser::NO_ANSWER, count($order)),
        ]);

        return $order;
    }

    /**
     * A fresh randomised order for one contestant.
     *
     * @return list<int>
     */
    public function build(CompetitionSettings $settings): array
    {
        $questionIds = CompetitionQuestion::query()
            ->orderBy('question_number')
            ->pluck('id')
            ->all();

        // Refuse rather than hand out a short paper: a contestant sitting 60 of
        // 75 questions would be silently disadvantaged against the field.
        if (count($questionIds) < $settings->questionCount()) {
            throw ExamException::paperNotReady();
        }

        shuffle($questionIds);

        return array_values(array_map('intval', array_slice($questionIds, 0, $settings->questionCount())));
    }
}
