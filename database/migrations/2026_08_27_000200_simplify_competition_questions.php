<?php

/**
 * Madad Phase 1 — competition_questions loses competition_id.
 *
 * There is one competition, so the column partitioned a bank that is never
 * partitioned. The unique that made the Excel import idempotent moves with it:
 * (competition_id, question_number) becomes (question_number), which is the
 * same guarantee once the scope is a constant.
 *
 * Nothing about a question changes. No row is inserted, updated or deleted here
 * — this migration only removes a constraint, an index and a column.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('competition_questions', 'competition_id')) {
            return;
        }

        // Widening the unique from (competition_id, question_number) to
        // (question_number) is only safe if question_number is ALREADY unique
        // on its own. With one competition it is; refuse rather than discover
        // it half way through an ALTER.
        $collisions = DB::table('competition_questions')
            ->select('question_number')
            ->groupBy('question_number')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($collisions > 0) {
            throw new RuntimeException(
                "question_number is not unique on its own ({$collisions} duplicated values). "
                .'Collapsing to a single competition would violate the new unique index.'
            );
        }

        Schema::table('competition_questions', function (Blueprint $table) {
            $table->dropForeign('fk_competition_questions_competition');
            $table->dropUnique('uq_competition_questions_competition_number');
            $table->unique('question_number', 'uq_competition_questions_number');
            $table->dropColumn('competition_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('competition_questions', 'competition_id')) {
            return;
        }

        Schema::table('competition_questions', function (Blueprint $table) {
            $table->unsignedBigInteger('competition_id')->default(1)->after('id');
        });

        Schema::table('competition_questions', function (Blueprint $table) {
            $table->dropUnique('uq_competition_questions_number');
            $table->unique(
                ['competition_id', 'question_number'],
                'uq_competition_questions_competition_number'
            );

            if (Schema::hasTable('competitions')) {
                $table->foreign('competition_id', 'fk_competition_questions_competition')
                    ->references('id')->on('competitions')
                    ->cascadeOnDelete()->restrictOnUpdate();
            }
        });
    }
};
