<?php

/**
 * Madad Phase 1 — the arrival anchor is removed.
 *
 * current_question_started_at belonged to the previous, arrival-based timing
 * model: a window ran from the moment the contestant REACHED a position, capped
 * at seconds_per_question. Under the confirmed business rule there is exactly
 * one timing model, and it is anchored to the exam start alone:
 *
 *     slot i  =  [ started_at + i·s ,  started_at + (i+1)·s )
 *
 * Answering early does not shift that grid, a reconnect does not open a fresh
 * window, and nothing about when a contestant arrived anywhere is persisted.
 * Every timestamp the API still reports — opened_at, expires_at — is derived
 * from started_at on the request that reports it.
 *
 * Dropping the column is what makes the old model unreachable rather than
 * merely unused: no code can quietly start reading it again.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('competition_users', 'current_question_started_at')) {
            return;
        }

        Schema::table('competition_users', function (Blueprint $table) {
            $table->dropColumn('current_question_started_at');
        });
    }

    /**
     * Restores the column, but not its values — they are gone, and inventing
     * arrival times would be worse than a null. A rollback to the old engine
     * would rebuild them from started_at + index·seconds_per_question, which is
     * exactly what the backfill migration already does.
     */
    public function down(): void
    {
        if (Schema::hasColumn('competition_users', 'current_question_started_at')) {
            return;
        }

        Schema::table('competition_users', function (Blueprint $table) {
            $table->dateTime('current_question_started_at', 3)->nullable()->after('current_question');
        });
    }
};
