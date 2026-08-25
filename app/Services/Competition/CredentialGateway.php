<?php

namespace App\Services\Competition;

/**
 * The Email Gateway seam.
 *
 * The plaintext credential is passed as an argument and is never persisted by
 * any implementation. Implementations must not log it either — LogGateway
 * records that a message was sent, not what it contained.
 *
 * No vendor implementation exists yet because no gateway credentials or
 * configuration have been supplied. Binding a real one means adding a class
 * here and one line in a service provider.
 */
interface CredentialGateway
{
    public function send(string $email, string $name, string $plaintextPassword): GatewayResult;
}
