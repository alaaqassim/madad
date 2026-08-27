<?php

/**
 * madad_top100: the winners list, ready to read.
 *
 *     SELECT * FROM madad_top100;
 *
 * It is defined on top of madad_results rather than repeating the ORDER BY, so
 * the ranking still lives in exactly one place. Changing the tie-break changes
 * both views at once.
 *
 * The two views answer different questions:
 *
 *     madad_top100   who won            (the first 100, nothing else)
 *     madad_results  where is X ranked  (every completed contestant)
 *
 * Looking one contestant up in madad_results returns their true rank even
 * though the WHERE runs outside the view: ROW_NUMBER() prevents MariaDB from
 * merging the view into the outer query, so the ranking is computed over the
 * whole field first and filtered afterwards. Verified against the development
 * data before this migration was written.
 *
 * The limit is fixed at 100 because that is the competition's rule, not a
 * pagination default. Change the rule, change this view.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS `madad_top100`');

        DB::statement(<<<'SQL'
            CREATE VIEW `madad_top100` AS
            SELECT * FROM `madad_results`
            ORDER BY `rank`
            LIMIT 100
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `madad_top100`');
    }
};
