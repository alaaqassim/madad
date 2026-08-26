<?php

/**
 * Madad Phase 1 — competition_users loses competition_id.
 *
 * Both composite uniques existed to scope a participation to a competition that
 * is always the same one. They collapse to their second column:
 *
 *   (competition_id, contestant_email) → (contestant_email)
 *   (competition_id, user_id)          → (user_id)
 *
 * idx_competition_users_user is dropped outright. It existed ONLY because
 * user_id was not the leading column of the old composite unique; the new
 * single-column unique serves both the hot "which participation does the
 * logged-in user own?" lookup — now a `const` rather than a `ref` — and the
 * foreign key. Keeping it would be a duplicate index on the same column.
 *
 * user_id stays nullable: a participation exists before its account, and InnoDB
 * permits many NULLs in a unique index, which is exactly what un-provisioned
 * rows need.
 *
 * No exam state is touched. question_order, current_question, answers,
 * started_at, completed_at and both aggregates ride through unchanged.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('competition_users', 'competition_id')) {
            return;
        }

        $this->refuseDuplicates('contestant_email');
        $this->refuseDuplicates('user_id');

        Schema::table('competition_users', function (Blueprint $table) {
            $table->dropForeign('fk_competition_users_competition');
            $table->dropUnique('uq_competition_users_competition_email');

            // The user FK is dropped first because it sits on top of the
            // composite unique that is about to go; it is put back below.
            $table->dropForeign('fk_competition_users_user');
            $table->dropUnique('uq_competition_users_competition_user');
            $table->dropIndex('idx_competition_users_user');

            $table->unique('contestant_email', 'uq_competition_users_email');
            $table->unique('user_id', 'uq_competition_users_user');

            $table->foreign('user_id', 'fk_competition_users_user')
                ->references('id')->on('users')
                ->restrictOnDelete()->restrictOnUpdate();

            $table->dropColumn('competition_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('competition_users', 'competition_id')) {
            return;
        }

        Schema::table('competition_users', function (Blueprint $table) {
            $table->unsignedBigInteger('competition_id')->default(1)->after('id');
        });

        Schema::table('competition_users', function (Blueprint $table) {
            $table->dropForeign('fk_competition_users_user');
            $table->dropUnique('uq_competition_users_email');
            $table->dropUnique('uq_competition_users_user');

            $table->unique(['competition_id', 'contestant_email'], 'uq_competition_users_competition_email');
            $table->unique(['competition_id', 'user_id'], 'uq_competition_users_competition_user');
            $table->index('user_id', 'idx_competition_users_user');

            $table->foreign('user_id', 'fk_competition_users_user')
                ->references('id')->on('users')
                ->restrictOnDelete()->restrictOnUpdate();

            if (Schema::hasTable('competitions')) {
                $table->foreign('competition_id', 'fk_competition_users_competition')
                    ->references('id')->on('competitions')
                    ->cascadeOnDelete()->restrictOnUpdate();
            }
        });
    }

    /** A composite unique can hide duplicates that a single-column one cannot. */
    private function refuseDuplicates(string $column): void
    {
        $duplicates = DB::table('competition_users')
            ->select($column)
            ->whereNotNull($column)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicates > 0) {
            throw new RuntimeException(
                "competition_users.{$column} is not unique on its own ({$duplicates} duplicated values). "
                .'Dropping competition_id would violate the new unique index.'
            );
        }
    }
};
