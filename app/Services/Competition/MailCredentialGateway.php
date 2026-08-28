<?php

namespace App\Services\Competition;

use App\Mail\ContestantCredentials;
use App\Models\CompetitionSettings;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers credentials over whatever mailer the environment configures.
 *
 * The gateway seam exists so the rest of the system never learns how mail is
 * actually sent. This implementation keeps that promise in both directions: it
 * takes an address, a name and a password, and it answers only "delivered" or
 * "failed, and here is why" - the same two answers the log gateway gives.
 *
 * ─── ON THE PASSWORD ────────────────────────────────────────────────────────
 * It is passed straight to the message and never touched again. It is not
 * logged, not stored, and not queued: Mail::send() rather than Mail::queue(),
 * because queueing serialises the mailable into the jobs table and a password
 * in a database row is exactly what the design forbids. Sending stays
 * synchronous, which for a roster of a thousand is a command that takes a few
 * minutes and can be rerun.
 *
 * ─── ON FAILURE ─────────────────────────────────────────────────────────────
 * Every throwable is caught and reported as a failure. A gateway that let an
 * exception escape would abort the provisioning run partway through, marking
 * nobody after that point - so one bad address would silently cost hundreds of
 * contestants their credentials. Failing one row keeps the run going, and
 * `madad:provision --retry-failed` picks it up afterwards.
 */
class MailCredentialGateway implements CredentialGateway
{
    public function send(string $email, string $name, string $plaintextPassword): GatewayResult
    {
        try {
            Mail::to($email)->send(new ContestantCredentials(
                contestantName: $name,
                plaintextPassword: $plaintextPassword,
                loginEmail: $email,
                competition: CompetitionSettings::current(),
            ));
        } catch (Throwable $e) {
            // The class name is kept: "Connection could not be established"
            // means little on its own, and this string is all the operator
            // sees in email_last_error.
            return GatewayResult::failed(class_basename($e).': '.$e->getMessage());
        }

        return GatewayResult::delivered();
    }
}
