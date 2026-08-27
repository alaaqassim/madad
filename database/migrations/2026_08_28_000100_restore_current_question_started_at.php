<?php

/**
 * Madad Phase 1 — the active question's own anchor comes back, deliberately.
 *
 * Under the previous rule every position owned a fixed slot measured from the
 * exam start, so the whole timeline was arithmetic on `started_at` and nothing
 * per-question needed storing.
 *
 * The confirmed rule is now IMMEDIATE ADVANCE: answering early does not buy a
 * wait, it opens the next question there and then, with a fresh window of up to
 * seconds_per_question. That makes the timeline depend on WHEN each answer was
 * given — information the server does not otherwise keep and cannot recompute
 * from `started_at`, because two contestants who began together can be minutes
 * apart by question ten.
 *
 * So the anchor is not a convenience here; it is the only durable record of
 * when the live question became live. Without it a reconnect would have to
 * either restart the current question (giving back disconnected time) or guess.
 * Browser time cannot supply it, and the session must not: a contestant who
 * logs out, changes device, or loses their session would otherwise reset their
 * own clock.
 *
 * One column on the existing contestant row — NOT a per-question table, and NOT
 * a row per answer. The exam still lives entirely on competition_users.
 *
 * The backfill reconstructs the anchor for contestants already mid-exam using
 * the model they were actually playing under (started_at + index · seconds).
 * That is the honest value: it is where the previous engine believed their
 * current question had opened, so nobody gains or loses time at the boundary.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('competition_users', 'current_question_started_at')) {
            Schema::table('competition_users', function (Blueprint $table) {
                $table->dateTime('current_question_started_at', 3)
                    ->nullable()
                    ->after('current_question');
            });
        }

        $this->backfill();
    }

    /**
     * Drops the column. Safe only alongside a timing model that does not need
     * it — which, under immediate advance, no longer exists.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('competition_users', 'current_question_started_at')) {
            return;
        }

        Schema::table('competition_users', function (Blueprint $table) {
            $table->dropColumn('current_question_started_at');
        });
    }

    /**
     * Give every in-progress contestant the anchor the old model implied.
     *
     * Rows that are not in progress keep NULL: a contestant who has not begun
     * has no live question, and a completed one has no live question either.
     */
    private function backfill(): void
    {
        $seconds = (int) (DB::table('competition_settings')
            ->where('id', 1)
            ->value('seconds_per_question') ?? 40);

        $seconds = max(1, $seconds);

        DB::table('competition_users')
            ->where('exam_status', 'in_progress')
            ->whereNotNull('started_at')
            ->whereNull('current_question_started_at')
            ->update([
                'current_question_started_at' => DB::raw(
                    "DATE_ADD(`started_at`, INTERVAL COALESCE(`current_question`, 0) * {$seconds} SECOND)"
                ),
            ]);
    }
};
