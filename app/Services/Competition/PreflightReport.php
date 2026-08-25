<?php

namespace App\Services\Competition;

/**
 * The outcome of a preflight run: every check, plus the single verdict an
 * operator reads before deciding whether to open the portal.
 *
 * FAIL beats WARNING beats PASS. Warnings are reported separately from
 * blockers on purpose — failed credential emails are a real operational
 * problem, but no stated business rule says they stop the competition, so they
 * must not masquerade as one.
 */
readonly class PreflightReport
{
    /** @param list<PreflightCheck> $checks */
    public function __construct(public array $checks) {}

    public function verdict(): string
    {
        if ($this->failures() !== []) {
            return PreflightCheck::FAIL;
        }

        return $this->warnings() === [] ? PreflightCheck::PASS : PreflightCheck::WARNING;
    }

    public function passed(): bool
    {
        return $this->failures() === [];
    }

    /** @return list<PreflightCheck> */
    public function failures(): array
    {
        return array_values(array_filter($this->checks, fn (PreflightCheck $c) => $c->isFailure()));
    }

    /** @return list<PreflightCheck> */
    public function warnings(): array
    {
        return array_values(array_filter($this->checks, fn (PreflightCheck $c) => $c->isWarning()));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict(),
            'checks' => array_map(fn (PreflightCheck $c) => [
                'area' => $c->area,
                'name' => $c->name,
                'status' => $c->status,
                'detail' => $c->detail,
            ], $this->checks),
        ];
    }
}
