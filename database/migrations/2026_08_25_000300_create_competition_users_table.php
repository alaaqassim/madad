<?php

/**
 * Madad Phase 1 — competition_users.
 *
 * One row per contestant participation. It carries, deliberately in one table:
 * import staging, account-provisioning state, Email Gateway delivery state,
 * exam state, and the final result.
 *
 * `user_id` is NULLABLE by design. The participation row is created BEFORE the
 * account, so that a failed account creation is a visible, retryable row rather
 * than no record at all. InnoDB permits many NULLs in a unique index, which is
 * exactly what un-provisioned rows need.
 *
 * No plain-text password is stored here, or anywhere. The generated secret is
 * hashed into users.password and passed to the gateway transiently. A failed
 * delivery is retried by generating a NEW password and replacing the hash —
 * never by storing or reusing the old plaintext.
 *
 * Current question position is NOT stored: it is derived from
 * competition_user_questions as the lowest sequence not yet finalised.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('competition_id');
            $table->unsignedBigInteger('user_id')->nullable();

            // Import record — survives independently of whether the account was ever made.
            $table->string('contestant_name', 191);
            $table->string('contestant_email', 191);
            $table->string('source_reference', 100)->nullable();

            // Account provisioning state.
            $table->enum('account_status', ['pending', 'created', 'failed'])->default('pending');
            $table->dateTime('credentials_generated_at')->nullable();

            // Email Gateway delivery state.
            $table->enum('email_status', ['pending', 'sent', 'failed'])->default('pending');
            $table->unsignedTinyInteger('email_attempts')->default(0);
            $table->dateTime('credentials_sent_at')->nullable();
            $table->string('email_last_error', 500)->nullable();

            // Exam state. datetime(3) preserves millisecond timing evidence.
            $table->enum('exam_status', ['not_started', 'in_progress', 'completed'])
                ->default('not_started');
            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('completed_at', 3)->nullable();

            // Result. correct_answers is the ranking key (all questions equally weighted).
            $table->unsignedSmallInteger('correct_answers')->default(0);
            $table->unsignedSmallInteger('answered_questions')->default(0);

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            // Blocks duplicate provisioning from the same source row.
            $table->unique(
                ['competition_id', 'contestant_email'],
                'uq_competition_users_competition_email'
            );

            // One participation per account. Leading column also serves the competition FK.
            $table->unique(
                ['competition_id', 'user_id'],
                'uq_competition_users_competition_user'
            );

            // "Which participation does the logged-in user own?" — runs on every
            // authenticated exam request. The unique above cannot serve it, because
            // user_id is not its leading column.
            $table->index('user_id', 'idx_competition_users_user');

            $table->foreign('competition_id', 'fk_competition_users_competition')
                ->references('id')->on('competitions')
                ->cascadeOnDelete()->restrictOnUpdate();

            // RESTRICT: deleting a user who holds a participation row would destroy
            // result evidence. It must fail loudly.
            $table->foreign('user_id', 'fk_competition_users_user')
                ->references('id')->on('users')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_users');
    }
};
