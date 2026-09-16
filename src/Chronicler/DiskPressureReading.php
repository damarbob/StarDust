<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

/**
 * One tick's disk-pressure reading, as a single immutable snapshot.
 *
 * {@see DiskPressureGate::sample()} probes the OS exactly once and
 * returns this — the fields `Chronicler::tickRound()` reads for the
 * `low_disk` event payload are guaranteed to describe the same probe
 * that decided {@see self::shouldSkipClaim()}, not a second, later
 * syscall that could disagree with it.
 */
final class DiskPressureReading
{
    public function __construct(
        public readonly string $partition,
        public readonly ?float $freePct,
        public readonly float $thresholdPct,
    ) {
    }

    public function shouldSkipClaim(): bool
    {
        return $this->freePct !== null && $this->freePct < $this->thresholdPct;
    }
}
