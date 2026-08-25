<?php

namespace App\Services\Competition;

/**
 * One competition-day check and its outcome.
 *
 * `detail` always says what was actually measured — a count, a name, a list of
 * ids — because "FAIL: exam data" without a number is not something an operator
 * can act on at 08:50 on competition morning.
 */
readonly class PreflightCheck
{
    public const PASS = 'PASS';

    public const WARNING = 'WARNING';

    public const FAIL = 'FAIL';

    private function __construct(
        public string $area,
        public string $name,
        public string $status,
        public string $detail,
    ) {}

    public static function pass(string $area, string $name, string $detail): self
    {
        return new self($area, $name, self::PASS, $detail);
    }

    /** Something an operator should know about but which does not block launch. */
    public static function warning(string $area, string $name, string $detail): self
    {
        return new self($area, $name, self::WARNING, $detail);
    }

    /** A hard blocker: the competition must not run in this state. */
    public static function fail(string $area, string $name, string $detail): self
    {
        return new self($area, $name, self::FAIL, $detail);
    }

    /** Convenience for "fail if the count is non-zero, otherwise pass". */
    public static function forCount(string $area, string $name, int $count, string $problem, string $clean): self
    {
        return $count === 0
            ? self::pass($area, $name, $clean)
            : self::fail($area, $name, "{$count} {$problem}");
    }

    public function isFailure(): bool
    {
        return $this->status === self::FAIL;
    }

    public function isWarning(): bool
    {
        return $this->status === self::WARNING;
    }
}
