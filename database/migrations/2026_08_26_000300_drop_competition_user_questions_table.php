<?php

/**
 * Madad Phase 1 — remove the per-question assignment model.
 *
 * competition_user_questions held one row per contestant per question, with its
 * own sequence, opened_at and expires_at. That is no longer how the exam is
 * modelled: the paper is an array on the participation and the position is an
 * index into it, so keeping this table would leave two architectures able to
 * answer "where is this contestant?" — the one thing the redesign forbids.
 *
 * The backfill migration must run before this one. down() restores the table
 * and its constraints but cannot restore the rows; the contestant papers now
 * live in competition_users.question_order / answers, which is what a rollback
 * would read from.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('competition_user_questions');
    }

    public function down(): void
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

            $table->unique(['competition_user_id', 'sequence'], 'uq_cuq_user_sequence');
            $table->unique(['competition_user_id', 'competition_question_id'], 'uq_cuq_user_question');

            $table->foreign('competition_user_id', 'fk_cuq_competition_user')
                ->references('id')->on('competition_users')
                ->cascadeOnDelete()->restrictOnUpdate();

            $table->foreign('competition_question_id', 'fk_cuq_competition_question')
                ->references('id')->on('competition_questions')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }
};
