<?php

/**
 * Madad Phase 1 — the Array + Index exam state model.
 *
 * The exam is no longer a table of assignment rows. A contestant's paper is one
 * randomised array of competition_questions.id values, and their position in it
 * is a zero-based index. Everything the engine needs now lives on the
 * participation row.
 *
 * `question_order` is a JSON array held as a string, not a native JSON column:
 * MariaDB 10.4's JSON type is only an alias for LONGTEXT with a check
 * constraint, so it buys nothing and costs portability. VARCHAR(1024) is sized
 * from the real encoding — 75 ids of d digits encode to 75d + 76 characters, so
 * the current bank (ids 76..150) is 277 characters and VARCHAR(255) would
 * already truncate it. 1024 holds ids up to 12 digits.
 *
 * `answers` is one character per index over {A,B,C,D,-}, positionally parallel
 * to question_order. '-' is "no answer": skipped, elapsed, or not yet reached.
 * 75 bytes replaces 75 rows, and correct_answers / answered_questions become
 * caches recomputed from it rather than a second source of truth.
 *
 * `current_question_started_at` is what makes the 40-second cap exact. The
 * contestant advances the instant they submit, so a fast contestant would
 * otherwise inherit the remainder of a fixed timeline slot — more than
 * seconds_per_question. Storing when they arrived at the current index gives
 * deadline = min(slot_end, arrived_at + seconds_per_question), and because
 * arrived_at is never later than the slot start, that minimum is always
 * arrived_at + seconds_per_question. It is ONE column for the current position,
 * not an opened_at/expires_at row per question.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_users', function (Blueprint $table) {
            $table->string('question_order', 1024)->nullable()->after('completed_at');
            $table->unsignedSmallInteger('current_question')->nullable()->after('question_order');
            $table->dateTime('current_question_started_at', 3)->nullable()->after('current_question');
            $table->string('answers', 255)->nullable()->after('current_question_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('competition_users', function (Blueprint $table) {
            $table->dropColumn([
                'question_order',
                'current_question',
                'current_question_started_at',
                'answers',
            ]);
        });
    }
};
