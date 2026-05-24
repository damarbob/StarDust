<?php

declare(strict_types=1);

namespace StarDust\Daemon;

/**
 * OR-composes any number of {@see ShutdownSignal} probes. The first
 * probe to return `true` short-circuits the composite.
 */
final class CompositeShutdownSignal implements ShutdownSignal
{
    /** @var list<ShutdownSignal> */
    private readonly array $signals;

    public function __construct(ShutdownSignal ...$signals)
    {
        $this->signals = array_values($signals);
    }

    public function isRequested(): bool
    {
        foreach ($this->signals as $signal) {
            if ($signal->isRequested()) {
                return true;
            }
        }
        return false;
    }
}
