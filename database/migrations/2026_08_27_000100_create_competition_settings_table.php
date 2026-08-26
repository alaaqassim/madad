<?php

/**
 * Madad Phase 1 — competition_settings.
 *
 * Madad runs ONE competition. The `competitions` table existed only because the
 * original design assumed several, and every `competition_id` in the schema was
 * the cost of that assumption. This table replaces it with the thing that was
 * actually needed: one row of global configuration.
 *
 * ─── WHY THE KEY IS FIXED, NOT AUTO_INCREMENT ───────────────────────────────
 * "There must be exactly one settings row" is a rule, and a rule the database
 * cannot enforce is a rule that eventually gets broken by a seeder, a fixture or
 * a tired operator. So the primary key is a fixed 1 rather than a sequence, with
 * CHECK (id = 1) on top. Between them a second row is impossible: id = 1 is
 * refused by the primary key, and anything else is refused by the constraint.
 *
 * (An AUTO_INCREMENT column cannot carry a CHECK in MariaDB — error 1901 — which
 * is the other reason the key is fixed. Nothing generates settings rows, so a
 * sequence bought nothing here in the first place.)
 *
 * ─── THE TWO KINDS OF TIME IN HERE ──────────────────────────────────────────
 * `starts_at` / `ends_at` are the GLOBAL availability window — when the portal
 * may be used at all. `exam_duration_minutes` is the PERSONAL allowance each
 * contestant gets from their own Begin. They are different concepts and a
 * contestant's effective end is the earlier of the two:
 *
 *     effective_end = min(competition_users.started_at + duration, ends_at)
 *
 * No end time is stored anywhere. It is derived on every request, so it cannot
 * drift from the values above.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('name', 191);

            $table->enum('status', ['draft', 'ready', 'open', 'closed'])->default('draft');
            $table->boolean('show_result')->default(false);

            $table->unsignedSmallInteger('question_count')->default(75);
            $table->unsignedSmallInteger('seconds_per_question')->default(40);

            // The contestant's personal allowance, from their own Begin.
            $table->unsignedSmallInteger('exam_duration_minutes')->default(60);

            // The global availability window. NULL on either side means
            // unbounded on that side — the status column then stands alone.
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        DB::statement(
            'ALTER TABLE `competition_settings`
             ADD CONSTRAINT `chk_competition_settings_singleton` CHECK (`id` = 1)'
        );

        $this->seedFromCompetitions();
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_settings');
    }

    /**
     * Carry the live configuration across, or start from the Phase 1 defaults.
     *
     * A database holding more than one competition cannot be collapsed without
     * someone deciding which one survives, and that is a business decision. It
     * stops here rather than picking silently.
     */
    private function seedFromCompetitions(): void
    {
        $now = now()->format('Y-m-d H:i:s');

        if (! Schema::hasTable('competitions')) {
            DB::table('competition_settings')->insert([
                'id' => 1,
                'name' => 'Madad Phase 1',
                'status' => 'draft',
                'show_result' => false,
                'question_count' => 75,
                'seconds_per_question' => 40,
                'exam_duration_minutes' => 60,
                'starts_at' => null,
                'ends_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $competitions = DB::table('competitions')->orderBy('id')->get();

        if ($competitions->count() > 1) {
            throw new RuntimeException(
                'competitions holds '.$competitions->count().' rows. Madad Phase 1 is a single-competition '
                .'system and this migration will not choose which one survives. Remove the extras first.'
            );
        }

        $source = $competitions->first();

        DB::table('competition_settings')->insert([
            'id' => 1,
            'name' => $source?->name ?? 'Madad Phase 1',
            'status' => $source?->status ?? 'draft',
            'show_result' => $source?->show_result ?? false,
            'question_count' => $source?->question_count ?? 75,
            'seconds_per_question' => $source?->seconds_per_question ?? 40,
            'exam_duration_minutes' => 60,
            'starts_at' => $source?->starts_at ?? null,
            'ends_at' => $source?->ends_at ?? null,
            'created_at' => $source?->created_at ?? $now,
            'updated_at' => $now,
        ]);
    }
};
