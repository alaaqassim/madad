<?php

/**
 * Madad Phase 1 — the results views stop needing anybody to settle first.
 *
 * ─── THE PROBLEM THEY HAD ───────────────────────────────────────────────────
 * Both views filtered on `exam_status = 'completed'`, which is stored state.
 * A contestant who walks away and never comes back has nothing touch their row,
 * so nothing marks them finished, so they were absent from the ranking, the top
 * hundred and the export - with their answers sitting intact in the table. A
 * hundred contestants in the development database were in that state, and the
 * only cure was remembering to run a command.
 *
 * ─── THE FIX ────────────────────────────────────────────────────────────────
 * `effective_end_at` is written at Begin, so the row already knows when this
 * contestant's exam runs out. The views now include anybody whose end has
 * passed, settled or not, and take their finishing time as
 *
 *     COALESCE(completed_at, effective_end_at)
 *
 * A settled contestant keeps the exact instant that was recorded for them. An
 * unsettled one is measured at the end they were always going to have. Nobody's
 * result depends on when - or whether - anybody ran anything.
 *
 * Note what this does NOT do: it does not recompute the end. min(allowance,
 * window) is calculated once in PHP and stored; these views only compare
 * against it. That is why there is still exactly one implementation of the
 * rule, and why PreflightService checks the stored values against the formula.
 *
 * The ordering below is unchanged - score, then the shorter attempt, then id -
 * and ResultsViewTest still compares it row by row against ResultService.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Finished: marked so, or the moment they were always going to end has passed. */
    private const FINISHED = "(`cu`.`exam_status` = 'completed'"
        .' OR (`cu`.`started_at` IS NOT NULL AND `cu`.`effective_end_at` IS NOT NULL AND `cu`.`effective_end_at` <= NOW(3)))';

    private const ENDED_AT = 'COALESCE(`cu`.`completed_at`, `cu`.`effective_end_at`)';

    public function up(): void
    {
        $this->create('madad_results', null);
        $this->create('madad_top100', 100);
    }

    /**
     * Both views are the same query; only the cut-off differs. Written once so
     * the two cannot drift apart from each other on top of everything else.
     */
    private function create(string $name, ?int $limit): void
    {
        $finished = self::FINISHED;
        $endedAt = self::ENDED_AT;

        $order = "`cu`.`correct_answers` DESC,
                  TIMESTAMPDIFF(MICROSECOND, `cu`.`started_at`, {$endedAt}) ASC,
                  `cu`.`id` ASC";

        // ROW_NUMBER() stops MariaDB merging a caller's WHERE into the view, so
        // looking one contestant up still reports their true rank.
        $body = <<<SQL
            SELECT
                ROW_NUMBER() OVER (ORDER BY {$order}) AS `rank`,
                `cu`.`id` AS `competition_user_id`,
                `cu`.`contestant_name`,
                `cu`.`contestant_email`,
                `cu`.`correct_answers`,
                (SELECT `question_count` FROM `competition_settings` WHERE `id` = 1) AS `total_questions`,
                `cu`.`answered_questions`,
                `cu`.`started_at`,
                {$endedAt} AS `completed_at`,
                TIMESTAMPDIFF(SECOND, `cu`.`started_at`, {$endedAt}) AS `duration_seconds`
            FROM `competition_users` AS `cu`
            WHERE {$finished}
            ORDER BY {$order}
        SQL;

        DB::statement("DROP VIEW IF EXISTS `{$name}`");

        DB::statement($limit === null
            ? "CREATE VIEW `{$name}` AS {$body}"
            : "CREATE VIEW `{$name}` AS SELECT * FROM ({$body}) AS `ranked` LIMIT {$limit}");
    }

    /**
     * Back to filtering on stored status alone. Correct only alongside
     * something that guarantees every finished exam has been settled.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `madad_results`');
        DB::statement('DROP VIEW IF EXISTS `madad_top100`');
    }
};
