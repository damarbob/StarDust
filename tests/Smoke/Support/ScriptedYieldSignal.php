<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use StarDust\Daemon\YieldSignal;

/**
 * Test double for {@see YieldSignal}: reports `$cause` starting from
 * the `$afterCalls`-th invocation (1-indexed) and `null` before that.
 *
 * `$afterCalls = 1` (the default) yields on the very first check, which
 * is the shape {@see \StarDust\Chronicler\ExportJobProcessor::process()}
 * actually queries — once per committed non-final chunk. Set it higher
 * to let N chunks commit before the yield fires, or leave it at
 * `PHP_INT_MAX` to script "never yields" explicitly, distinct from
 * passing `null` for "no signal at all".
 */
final class ScriptedYieldSignal implements YieldSignal
{
    private int $calls = 0;

    public function __construct(
        private readonly int $afterCalls = 1,
        private readonly string $cause = 'budget',
    ) {
    }

    public function yieldCause(): ?string
    {
        $this->calls++;
        return $this->calls >= $this->afterCalls ? $this->cause : null;
    }

    public function callCount(): int
    {
        return $this->calls;
    }
}
