<?php

/**
 * Madad Phase 1 — the competitions table is removed.
 *
 * Its configuration lives in competition_settings, its foreign keys are gone,
 * and nothing in app/, routes/, config/, resources/ or database/seeders reads
 * it. This runs last, after both children have been unscoped, because a table
 * with inbound foreign keys cannot be dropped.
 *
 * The guard below is the point of the migration: it refuses to drop the table
 * while anything still references it, so "nothing depends on it any more" is
 * proven by the database rather than asserted by a comment.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('competitions')) {
            return;
        }

        $referencing = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('REFERENCED_TABLE_NAME', 'competitions')
            ->pluck('CONSTRAINT_NAME')
            ->all();

        if ($referencing !== []) {
            throw new RuntimeException(
                'competitions is still referenced by: '.implode(', ', $referencing)
                .'. Those foreign keys must be removed before the table can be dropped.'
            );
        }

        Schema::drop('competitions');
    }

    /** Recreated empty and unreferenced — the configuration lives elsewhere now. */
    public function down(): void
    {
        if (Schema::hasTable('competitions')) {
            return;
        }

        Schema::create('competitions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 191);

            $table->enum('status', ['draft', 'ready', 'open', 'closed'])->default('draft');
            $table->boolean('show_result')->default(false);

            $table->unsignedSmallInteger('question_count')->default(75);
            $table->unsignedSmallInteger('seconds_per_question')->default(40);

            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }
};
