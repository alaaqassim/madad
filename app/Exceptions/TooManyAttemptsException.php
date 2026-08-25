<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * The per-email+IP login limiter has locked this key out.
 *
 * Distinct from the route throttle (which Laravel answers with 429) only in how
 * it is produced; both surface the same `too_many_attempts` code so the client
 * has one branch to write.
 */
class TooManyAttemptsException extends ValidationException
{
    public const REASON = 'too_many_attempts';
}
