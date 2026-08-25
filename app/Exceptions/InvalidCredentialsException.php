<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Login refused.
 *
 * A ValidationException subclass on purpose: the response keeps Laravel's
 * familiar {message, errors} shape, so nothing about the wire format changes,
 * while carrying a stable machine-readable code for the Vue client.
 *
 * The wording is identical for a wrong password and an unknown address — see
 * LoginRequest — so this code can never be used to enumerate the roster.
 */
class InvalidCredentialsException extends ValidationException
{
    public const REASON = 'invalid_credentials';
}
