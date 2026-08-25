<?php

/**
 * Madad Phase 1 — competition_user_questions.
 *
 * The contestant's personal paper: randomised assignment, persisted order,
 * per-question timing, answer, correctness and timeout state, in one row per
 * question per contestant (~75,000 rows at Phase 1 scale).
 *
 * The two unique constraints are what make the paper stable: a refresh, a
 * re-login, or a duplicate start request cannot reshuffle the order or insert a
 * second copy of a question.
 *
 * `opened_at` / `expires_at` are written by the server from the server clock.
 * `expires_at` is a stored SNAPSHOT, not a computed value, so a later edit to
 * competitions.seconds_per_question cannot move a deadline already issued.
 * datetime(3) preserves millisecond timing evidence.
 *
 * `is_correct` is a derived boolean and must be excluded from any API resource
 * served to the contestant during the exam.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_user_questions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('competition_user_id');
            $table->unsignedBigInteger('competition_question_id');

            $table->unsignedSmallInteger('sequence');

            $table->dateTime('opened_at', 3)->nullable();
            $table->dateTime('expires_at', 3)->nullable();

            $table->enum('selected_option', ['A', 'B', 'C', 'D'])->nullable();
            $table->dateTime('answered_at', 3)->nullable();

            $table->boolean('is_correct')->default(false);
            $table->boolean('timed_out')->default(false);

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            // Blocks duplicate sequence positions. Also the read path for
            // "fetch this contestant's paper in order" and "fetch sequence N".
            // Leading column serves the competition_user_id foreign key.
            $table->unique(['competition_user_id', 'sequence'], 'uq_cuq_user_sequence');

            // Blocks the same question appearing twice on one paper.
            $table->unique(
                ['competition_user_id', 'competition_question_id'],
                'uq_cuq_user_question'
            );

            $table->foreign('competition_user_id', 'fk_cuq_competition_user')
                ->references('id')->on('competition_users')
                ->cascadeOnDelete()->restrictOnUpdate();

            // RESTRICT: deleting a question already issued to contestants would
            // destroy answer evidence.
            $table->foreign('competition_question_id', 'fk_cuq_competition_question')
                ->references('id')->on('competition_questions')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_user_questions');
    }
};
