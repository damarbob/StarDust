<?php

declare(strict_types=1);

namespace StarDust\Daemon;

/**
 * OR-composes any number of {@see YieldSignal} probes (ADR 0050),
 * mirroring {@see CompositeShutdownSignal}. The first probe to report
 * a non-null cause short-circuits the composite.
 */
final class CompositeYield implements YieldSignal
{
    /** @var list<YieldSignal> */
    private readonly array $signals;

    public function __construct(YieldSignal ...$signals)
    {
        $this->signals = array_values($signals);
    }

    public function yieldCause(): ?string
    {
        foreach ($this->signals as $signal) {
            $cause = $signal->yieldCause();
            if ($cause !== null) {
                return $cause;
            }
        }
        return null;
    }
}
