<?php

namespace App\Services\Competition;

use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use Illuminate\Support\Collection;

/**
 * Result extraction.
 *
 * ─── THE RANKING, IN FULL ───────────────────────────────────────────────────
 *
 *   1. correct_answers   DESC   the score
 *   2. duration          ASC    completed_at − started_at — THE TIE-BREAK
 *   3. id                ASC    stability only, never a ruling
 *
 * The confirmed business rule: contestants level on score are separated by who
 * finished FASTER — the shorter attempt wins. It is applied as a secondary sort
 * rather than only at the boundary, which resolves the case the rule was asked
 * for (the 101st matching the 100th) and stays consistent everywhere else.
 *
 * Duration, not finishing time. Whoever begins later inside a window that is
 * open for hours is not slower for it, so the clock that counts is the
 * contestant's own — measured to the microsecond, because datetime(3) can hold
 * two attempts that agree to the second.
 *
 * `id ASC` remains a stability device for pagination and diffing, NOT a ranking
 * rule, and it must not be presented to the business as one. A cutoff is
 * reported as CONTESTED only when the tie-break itself cannot separate the
 * boundary — equal score AND equal duration — which is the one case left that
 * needs a human.
 *
 * No rank is stored: rank is a property of a query, not of a contestant.
 */
class ResultService
{
    /**
     * The attempt's length in microseconds, as SQL.
     *
     * Written once and reused by the ordering and by the export, so a row can
     * never be ranked by one measure and reported with another. NULL anchors
     * are impossible on a completed row (preflight enforces it) but sort last
     * rather than first if one ever appears.
     */
    private const DURATION_SQL = 'COALESCE(TIMESTAMPDIFF(MICROSECOND, started_at, completed_at), 9223372036854775807)';

    /** @return Collection<int, CompetitionUser> */
    public function completed(?int $limit = null): Collection
    {
        $query = CompetitionUser::query()
            ->where('exam_status', CompetitionUser::EXAM_COMPLETED)
            ->select('*')
            ->selectRaw(self::DURATION_SQL.' AS duration_micros')
            ->orderByDesc('correct_answers')
            ->orderByRaw(self::DURATION_SQL.' ASC')   // the tie-break: faster wins
            ->orderBy('id')                           // stability only
            ->with('user:id,name,email');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * One export row.
     *
     * No password, no hash, no answer key, no per-question detail — an export
     * that carried any of those would be a leak the moment it left the server.
     * `total_questions` comes from the settings row, not from a count of the
     * contestant's rows, so a short paper would show up as a discrepancy rather
     * than be silently normalised away.
     *
     * @return array<string, mixed>
     */
    public function row(CompetitionUser $participation, CompetitionSettings $settings): array
    {
        return [
            'competition_user_id' => $participation->id,
            'name' => $participation->contestant_name,
            'email' => $participation->contestant_email,
            'correct_answers' => $participation->correct_answers,
            'total_questions' => $settings->questionCount(),
            'answered_questions' => $participation->answered_questions,
            'started_at' => $participation->started_at?->toIso8601String(),
            'completed_at' => $participation->completed_at?->toIso8601String(),
            // The tie-break made visible. A ranking nobody can audit from the
            // file is a ranking nobody can defend.
            'duration_seconds' => $this->durationSeconds($participation),
        ];
    }

    /** The attempt's length in whole seconds, or null if either anchor is missing. */
    public function durationSeconds(CompetitionUser $participation): ?int
    {
        if ($participation->started_at === null || $participation->completed_at === null) {
            return null;
        }

        return (int) floor($participation->started_at->diffInMilliseconds($participation->completed_at, false) / 1000);
    }

    /**
     * Export-ready Top N.
     *
     * @return array<string, mixed>
     */
    public function topN(CompetitionSettings $settings, int $limit = 100): array
    {
        // 0 (or less) means "every completed contestant" — the operator asking
        // for the whole field rather than a leaderboard.
        $rows = $this->completed($limit > 0 ? $limit : null);
        $last = $rows->last();
        $cutoffScore = $last?->correct_answers;

        $tiedOnScore = $cutoffScore === null ? 0 : CompetitionUser::query()
            ->where('exam_status', CompetitionUser::EXAM_COMPLETED)
            ->where('correct_answers', $cutoffScore)
            ->count();

        /*
         * The boundary is decided unless the tie-break itself ties. Anyone
         * OUTSIDE the list who matches the last included contestant on BOTH
         * score and duration is genuinely indistinguishable from them, and no
         * stated rule separates the two.
         */
        $indistinguishable = 0;

        if ($last !== null && $limit > 0) {
            $included = $rows->pluck('id')->all();

            $indistinguishable = CompetitionUser::query()
                ->where('exam_status', CompetitionUser::EXAM_COMPLETED)
                ->where('correct_answers', $cutoffScore)
                ->whereIntegerNotInRaw('id', $included)
                ->whereRaw(self::DURATION_SQL.' = ?', [$last->duration_micros])
                ->count();
        }

        return [
            'competition' => $settings->name,
            'limit' => $limit,
            'returned' => $rows->count(),
            'total_completed' => CompetitionUser::query()
                ->where('exam_status', CompetitionUser::EXAM_COMPLETED)
                ->count(),
            'ordered_by' => 'correct_answers DESC, duration ASC',
            'tie_break_rule' => 'fastest_completion',
            'cutoff_score' => $cutoffScore,
            'cutoff_duration_seconds' => $last === null ? null : $this->durationSeconds($last),
            // Decided by the tie-break unless someone outside the list matches
            // the boundary on score AND duration both.
            'cutoff_is_contested' => $indistinguishable > 0,
            'contestants_tied_at_cutoff' => $tiedOnScore,
            'contestants_indistinguishable_at_cutoff' => $indistinguishable,
            'rows' => $rows->map(fn (CompetitionUser $p) => $this->row($p, $settings))->all(),
        ];
    }
}
