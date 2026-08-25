<?php

namespace App\Services\Competition;

use App\Models\CompetitionUser;
use Throwable;

/**
 * Provisions an account and delivers its credential, recording the outcome on
 * competition_users.
 *
 * Retry is re-issue, not resend. Because the plaintext is never persisted, a
 * failed delivery cannot be replayed — so a retry generates a NEW password,
 * replaces the stored hash, and sends that. This is safe rather than merely
 * acceptable: if the first message genuinely failed, the contestant never
 * learned the first password, so invalidating it costs nothing. The same
 * participation row and the same user row are reused throughout, so no retry
 * can produce a second account.
 */
class CredentialDeliveryService
{
    public function __construct(
        private readonly ContestantProvisioningService $provisioning,
        private readonly CredentialGateway $gateway,
    ) {}

    /**
     * Provision if needed, then attempt delivery.
     *
     * @return bool whether the gateway accepted the message
     */
    public function deliver(CompetitionUser $participation): bool
    {
        try {
            $plaintext = $this->provisioning->provision($participation);
        } catch (Throwable $e) {
            $this->provisioning->markFailed($participation);
            $this->recordFailure($participation, 'Account creation failed: '.$e->getMessage());

            return false;
        }

        return $this->dispatch($participation, $plaintext);
    }

    /**
     * Retry a failed delivery. Identical to deliver() by design — the whole
     * point is that a retry re-issues rather than replays.
     */
    public function retry(CompetitionUser $participation): bool
    {
        return $this->deliver($participation);
    }

    private function dispatch(CompetitionUser $participation, string $plaintext): bool
    {
        try {
            $result = $this->gateway->send(
                $participation->contestant_email,
                $participation->contestant_name,
                $plaintext,
            );
        } catch (Throwable $e) {
            $this->recordFailure($participation, $e->getMessage());

            return false;
        }

        if (! $result->delivered) {
            $this->recordFailure($participation, $result->error ?? 'Delivery failed.');

            return false;
        }

        $participation->forceFill([
            'email_status' => CompetitionUser::EMAIL_SENT,
            'email_attempts' => $participation->email_attempts + 1,
            'credentials_sent_at' => now(),
            'email_last_error' => null,
        ])->save();

        return true;
    }

    private function recordFailure(CompetitionUser $participation, string $error): void
    {
        $participation->forceFill([
            'email_status' => CompetitionUser::EMAIL_FAILED,
            'email_attempts' => $participation->email_attempts + 1,
            'credentials_sent_at' => null,
            'email_last_error' => mb_substr($error, 0, 500),
        ])->save();
    }
}
