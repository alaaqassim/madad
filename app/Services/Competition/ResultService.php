<?php

namespace App\Services\Competition;

use App\Models\Competition;
use App\Models\CompetitionUser;
use Illuminate\Support\Collection;

/**
 * Result extraction.
 *
 * ⚠️ THE TIE-BREAK RULE IS AN OPEN BUSINESS DECISION AND IS NOT IMPLEMENTED.
 *
 * Ranking is by correct_answers DESC and nothing else. `id ASC` is appended
 * purely so that repeated extractions of the same data return rows in the same
 * order — it is a stability device for pagination and diffing, NOT a ranking
 * rule, and it must not be presented to the business as one.
 *
 * Because of that, extractions carry `tie_break_rule => null` and the Top-N
 * export reports whether the cutoff falls inside a group of tied scores. If it
 * does, who takes the last place is undecided and a human has to rule on it.
 *
 * No rank is stored: rank is a property of a query, not of a contestant.
 */
class ResultService
{
    /** @return Collection<int, CompetitionUser> */
    public function completed(Competition $competition, ?int $limit = null): Collection
    {
        $query = CompetitionUser::query()
            ->where('competition_id', $competition->id)
            ->where('exam_status', CompetitionUser::EXAM_COMPLETED)
            ->orderByDesc('correct_answers')
            ->orderBy('id')                 // stability only — see class docblock
            ->with('user:id,name,email');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Export-ready Top N.
     *
     * @return array<string, mixed>
     */
    public function topN(Competition $competition, int $limit = 100): array
    {
        $rows = $this->completed($competition, $limit);
        $cutoffScore = $rows->last()?->correct_answers;

        $tiedAtCutoff = $cutoffScore === null ? 0 : CompetitionUser::query()
            ->where('competition_id', $competition->id)
            ->where('exam_status', CompetitionUser::EXAM_COMPLETED)
            ->where('correct_answers', $cutoffScore)
            ->count();

        $withinCutoff = $rows->where('correct_answers', $cutoffScore)->count();

        return [
            'competition' => $competition->name,
            'limit' => $limit,
            'returned' => $rows->count(),
            'total_completed' => CompetitionUser::query()
                ->where('competition_id', $competition->id)
                ->where('exam_status', CompetitionUser::EXAM_COMPLETED)
                ->count(),
            'ordered_by' => 'correct_answers DESC',
            'tie_break_rule' => null,
            'cutoff_score' => $cutoffScore,
            // If more contestants share the cutoff score than there are places
            // left, the boundary is genuinely undecided.
            'cutoff_is_contested' => $cutoffScore !== null && $tiedAtCutoff > $withinCutoff,
            'contestants_tied_at_cutoff' => $tiedAtCutoff,
            'rows' => $rows->map(fn (CompetitionUser $p) => [
                'competition_user_id' => $p->id,
                'name' => $p->contestant_name,
                'email' => $p->contestant_email,
                'correct_answers' => $p->correct_answers,
                'answered_questions' => $p->answered_questions,
                'started_at' => $p->started_at?->toIso8601String(),
                'completed_at' => $p->completed_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
