<?php

/**
 * Madad Phase 1 — one correct way to read the results out of SQL.
 *
 * The project manager queries the database directly, and the ranking is no
 * longer something you can write from memory: it is score DESC, then the
 * SHORTER ATTEMPT (the confirmed tie-break), then id for stability. Anyone
 * hand-writing that ORDER BY and forgetting the duration term gets a list that
 * looks entirely plausible and quietly ignores the tie-break.
 *
 * So the ordering is published once, here, and read as a plain table:
 *
 *     SELECT * FROM madad_results LIMIT 100;
 *
 * A view rather than a stored procedure on purpose — it composes (WHERE, LIMIT,
 * JOIN), it works in every GUI client without CALL syntax, and it needs no
 * parameters.
 *
 * ─── THIS IS A SECOND IMPLEMENTATION, AND THAT IS THE RISK ──────────────────
 * The authority is ResultService. This view repeats its ORDER BY in SQL, so the
 * two could drift the day the rule changes. `ResultsViewTest` compares them row
 * by row against data whose ties can only be broken by duration, and fails the
 * moment they disagree. Change the rule in one place and the suite stops you.
 *
 * ─── WHAT IT DELIBERATELY DOES NOT EXPOSE ───────────────────────────────────
 * No `answers`, no `question_order`, no `user_id`, and nothing from `users` —
 * so no password hash can leave through it. Only the columns the CSV export
 * already publishes.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS `madad_results`');

        DB::statement(<<<'SQL'
            CREATE VIEW `madad_results` AS
            SELECT
                ROW_NUMBER() OVER (
                    ORDER BY `cu`.`correct_answers` DESC,
                             TIMESTAMPDIFF(MICROSECOND, `cu`.`started_at`, `cu`.`completed_at`) ASC,
                             `cu`.`id` ASC
                ) AS `rank`,
                `cu`.`id` AS `competition_user_id`,
                `cu`.`contestant_name`,
                `cu`.`contestant_email`,
                `cu`.`correct_answers`,
                (SELECT `question_count` FROM `competition_settings` WHERE `id` = 1) AS `total_questions`,
                `cu`.`answered_questions`,
                `cu`.`started_at`,
                `cu`.`completed_at`,
                TIMESTAMPDIFF(SECOND, `cu`.`started_at`, `cu`.`completed_at`) AS `duration_seconds`
            FROM `competition_users` AS `cu`
            WHERE `cu`.`exam_status` = 'completed'
            ORDER BY `cu`.`correct_answers` DESC,
                     TIMESTAMPDIFF(MICROSECOND, `cu`.`started_at`, `cu`.`completed_at`) ASC,
                     `cu`.`id` ASC
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `madad_results`');
    }
};
