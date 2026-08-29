<?php

/**
 * Madad Phase 1 — when this contestant's exam runs out, written down.
 *
 * ─── WHY A STORED COLUMN AT ALL ─────────────────────────────────────────────
 * Everything derivable in this schema is derived: email status, rank, question
 * expiry. `exam_status` was the exception - stored state that goes stale, and
 * it goes stale in exactly one case: the contestant who walks away and never
 * comes back. Nothing touches their row, so nothing computes that their time
 * is up, so they sit `in_progress` for ever - with their answers intact and
 * invisible to every results surface, all of which filter on `completed`.
 *
 * A hundred contestants in the development database were in that state.
 *
 * The usual fix is something that runs at the moment their time expires. There
 * is nothing to run it: no cron and no persistent process on the production
 * server. So the fix is to need no such thing. At Begin both operands of the
 * end are already known - started_at, and the smaller of the allowance and the
 * window - so the answer is written down once and read for ever after. A view
 * can then see that a contestant's end has passed without anybody executing
 * anything at all.
 *
 * ─── WHY THIS IS NOT A SECOND COPY OF THE RULE ──────────────────────────────
 * The value is computed by CompetitionSettings::effectiveEndFor(), the one
 * place that formula exists, and stored. Nothing recomputes it: the views
 * COMPARE against it. That is the difference between a snapshot and a
 * duplicate, and it is the same pattern as question_order - a decision taken
 * once at Begin and then held.
 *
 * It does mean the stored value is a snapshot. An operator who changes ends_at
 * or exam_duration_minutes mid-competition makes it disagree with what the
 * formula would now say, which is why preflight grows a drift check and why
 * the standing rule - settings are not touched once a contestant has begun -
 * is the same rule that already governs question_count.
 *
 * ─── THE BACKFILL ───────────────────────────────────────────────────────────
 * Every contestant who has already begun gets the value their exam was always
 * running under. Nobody's end moves; this only writes down an end that was
 * already true and was being recomputed on every request.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('competition_users', 'effective_end_at')) {
            Schema::table('competition_users', function (Blueprint $table) {
                $table->dateTime('effective_end_at', 3)
                    ->nullable()
                    ->after('started_at');
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('competition_users', 'effective_end_at')) {
            return;
        }

        Schema::table('competition_users', function (Blueprint $table) {
            $table->dropColumn('effective_end_at');
        });
    }

    /**
     * min(started_at + allowance, ends_at) for everyone already under way.
     *
     * Written in SQL here and only here, because a migration cannot call into
     * application code it may outlive. PreflightService checks the stored
     * values against what the PHP formula says, so a disagreement between the
     * two - here or later - is reported rather than silently believed.
     */
    private function backfill(): void
    {
        $settings = DB::table('competition_settings')->where('id', 1)->first();

        if ($settings === null) {
            return;
        }

        $seconds = max(1, (int) ($settings->exam_duration_minutes ?? 60) * 60);

        $personalEnd = "DATE_ADD(`started_at`, INTERVAL {$seconds} SECOND)";

        $expression = $settings->ends_at === null
            ? $personalEnd
            : "LEAST({$personalEnd}, ".DB::getPdo()->quote($settings->ends_at).')';

        DB::table('competition_users')
            ->whereNotNull('started_at')
            ->whereNull('effective_end_at')
            ->update(['effective_end_at' => DB::raw($expression)]);
    }
};
