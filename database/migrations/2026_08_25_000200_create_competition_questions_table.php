<?php

/**
 * Madad Phase 1 — competition_questions.
 *
 * The imported question bank, mapped 1:1 onto the supplied Excel columns.
 *
 * `correct_option` is the answer key and is stored ONLY here. It must never be copied
 * onto a contestant-assignment row.
 *
 * The unique on (competition_id, question_number) both blocks duplicate questions and
 * gives the Excel import a stable key to upsert against, so a re-run is idempotent.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_questions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('competition_id');

            $table->unsignedSmallInteger('question_number');
            $table->text('question_text');

            $table->string('option_a', 500);
            $table->string('option_b', 500);
            $table->string('option_c', 500);
            $table->string('option_d', 500);

            $table->enum('correct_option', ['A', 'B', 'C', 'D']);

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            // Leading column competition_id also serves the foreign key below,
            // so InnoDB creates no duplicate index for it.
            $table->unique(
                ['competition_id', 'question_number'],
                'uq_competition_questions_competition_number'
            );

            $table->foreign('competition_id', 'fk_competition_questions_competition')
                ->references('id')->on('competitions')
                ->cascadeOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_questions');
    }
};
