<?php

namespace App\Services\Competition;

use Illuminate\Support\Facades\Log;

/**
 * Development gateway. Records that credentials were dispatched, deliberately
 * without the credential itself — a password in a log file is a password on
 * disk, which is exactly what the design forbids.
 *
 * Swap this binding for the vendor implementation once gateway configuration
 * is supplied.
 */
class LogCredentialGateway implements CredentialGateway
{
    public function send(string $email, string $name, string $plaintextPassword): GatewayResult
    {
        // $plaintextPassword is intentionally NOT logged.
        Log::info('Madad credential dispatch (development gateway)', [
            'recipient' => $email,
            'name' => $name,
            'password_length' => strlen($plaintextPassword),
        ]);

        return GatewayResult::delivered();
    }
}
