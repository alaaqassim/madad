<?php

namespace App\Services\Competition;

/** The outcome of one delivery attempt. Never carries the credential. */
readonly class GatewayResult
{
    private function __construct(
        public bool $delivered,
        public ?string $error = null,
    ) {}

    public static function delivered(): self
    {
        return new self(true);
    }

    public static function failed(string $error): self
    {
        // Bounded so a verbose gateway cannot overflow email_last_error.
        return new self(false, mb_substr($error, 0, 500));
    }
}
