<?php

/**
 * email_status is dropped. It restated two columns rather than adding anything.
 *
 * CredentialDeliveryService writes credentials_sent_at and email_attempts in
 * the same operation that used to write email_status, so the three states were
 * always fully determined by them:
 *
 *     credentials_sent_at IS NOT NULL  ->  sent
 *     email_attempts > 0               ->  failed   (sent already excluded)
 *     otherwise                        ->  pending
 *
 * Checked against all 1,000 development rows before dropping: zero rows
 * disagreed with the derivation in either direction.
 *
 * The value is still read as $participation->email_status, and in SQL through
 * CompetitionUser::EMAIL_STATUS_SQL or the whereEmailStatus() scope.
 *
 * NOT dropped alongside it: credentials_generated_at. It looked derivable from
 * user_id, but it records WHEN the current password was generated, and
 * madad:provision --retry-failed issues a new password to an existing account -
 * so it moves while users.created_at does not. Measured: all 900 provisioned
 * rows differ from users.created_at by more than two seconds. Dropping it would
 * lose real information.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('competition_users', 'email_status')) {
            return;
        }

        $this->refuseIfDerivationDisagrees();

        Schema::table('competition_users', function (Blueprint $table) {
            $table->dropColumn('email_status');
        });
    }

    /** Restores the column and rebuilds it from the two it was derived from. */
    public function down(): void
    {
        if (Schema::hasColumn('competition_users', 'email_status')) {
            return;
        }

        Schema::table('competition_users', function (Blueprint $table) {
            $table->enum('email_status', ['pending', 'sent', 'failed'])
                ->default('pending')
                ->after('credentials_generated_at');
        });

        DB::table('competition_users')->update([
            'email_status' => DB::raw(
                "CASE
                    WHEN credentials_sent_at IS NOT NULL THEN 'sent'
                    WHEN email_attempts > 0 THEN 'failed'
                    ELSE 'pending'
                END"
            ),
        ]);
    }

    /**
     * Abort rather than silently lose a state.
     *
     * If any row's stored status disagrees with the derivation, then the column
     * was carrying information after all and dropping it would destroy it.
     */
    private function refuseIfDerivationDisagrees(): void
    {
        $mismatched = DB::table('competition_users')
            ->whereRaw(
                "email_status <> CASE
                    WHEN credentials_sent_at IS NOT NULL THEN 'sent'
                    WHEN email_attempts > 0 THEN 'failed'
                    ELSE 'pending'
                END"
            )
            ->count();

        if ($mismatched > 0) {
            throw new RuntimeException(
                "REFUSED: {$mismatched} row(s) have an email_status that does not match "
                .'credentials_sent_at / email_attempts. The column is carrying information '
                .'that would be lost. Investigate before dropping it.'
            );
        }
    }
};
