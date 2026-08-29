<?php

namespace App\Services\Competition;

use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
    private const DURATION_SQL = 'COALESCE(TIMESTAMPDIFF(MICROSECOND, started_at, '.self::ENDED_AT.'), 9223372036854775807)';

    /**
     * When the attempt ended.
     *
     * A settled contestant has completed_at, which is the recorded instant and
     * is used unchanged. An unsettled one - somebody who walked away and never
     * came back, so nothing ever marked them finished - is measured at
     * effective_end_at, the end they were always going to have, written down at
     * Begin.
     */
    private const ENDED_AT = 'COALESCE(completed_at, effective_end_at)';

    /**
     * Finished, whether or not anybody settled them.
     *
     * Filtering on `exam_status` alone made a contestant's presence in the
     * ranking depend on somebody having run a command. It is stored state, and
     * it goes stale for precisely the contestant nobody touches again.
     */
    private const FINISHED_SQL = "exam_status = 'completed'"
        .' OR (started_at IS NOT NULL AND effective_end_at IS NOT NULL AND effective_end_at <= NOW(3))';

    /**
     * Everyone whose exam is over, by the one definition.
     *
     * Every count and every list below starts here, so a contestant can never
     * be ranked by one rule and counted by another.
     *
     * @return Builder<CompetitionUser>
     */
    private function finished(): Builder
    {
        return CompetitionUser::query()->whereRaw('('.self::FINISHED_SQL.')');
    }

    /** @return Collection<int, CompetitionUser> */
    public function completed(?int $limit = null): Collection
    {
        $query = $this->finished()
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
            'completed_at' => $this->endedAt($participation)?->toIso8601String(),
            // The tie-break made visible. A ranking nobody can audit from the
            // file is a ranking nobody can defend.
            'duration_seconds' => $this->durationSeconds($participation),
        ];
    }

    /** The attempt's length in whole seconds, or null if either anchor is missing. */
    public function durationSeconds(CompetitionUser $participation): ?int
    {
        $endedAt = $this->endedAt($participation);

        if ($participation->started_at === null || $endedAt === null) {
            return null;
        }

        return (int) floor($participation->started_at->diffInMilliseconds($endedAt, false) / 1000);
    }

    /** The PHP twin of ENDED_AT, so a row is never ranked one way and reported another. */
    public function endedAt(CompetitionUser $participation): ?Carbon
    {
        return $participation->completed_at ?? $participation->effective_end_at;
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

        $tiedOnScore = $cutoffScore === null ? 0 : $this->finished()
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

            $indistinguishable = $this->finished()
                ->where('correct_answers', $cutoffScore)
                ->whereIntegerNotInRaw('id', $included)
                ->whereRaw(self::DURATION_SQL.' = ?', [$last->duration_micros])
                ->count();
        }

        return [
            'competition' => $settings->name,
            'limit' => $limit,
            'returned' => $rows->count(),
            'total_completed' => $this->finished()
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
