<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

/**
 * One tick's disk-pressure reading, as a single immutable snapshot.
 *
 * {@see DiskPressureGate::sample()} probes exactly once and returns
 * this — the fields `Chronicler::tickRound()` reads for the `low_disk`
 * event payload are guaranteed to describe the same probe that decided
 * {@see self::shouldSkipClaim()}, not a second, later syscall that
 * could disagree with it.
 *
 * `$cause` is a clean partition rather than a set, because ADR 0051's
 * two checks short-circuit: the ratio runs first and returns
 * immediately on a trip, so `write_probe` means "the ratio passed AND
 * the probe failed". There is deliberately no `both`.
 */
final class DiskPressureReading
{
    /**
     * @param string      $partition    The directory actually probed.
     * @param float|null  $freePct      Ratio in `[0, 1]`, or null when
     *                                  the ratio probe was unavailable.
     * @param int         $probeBytes   0 when the write probe is disabled.
     * @param 'free_pct'|'write_probe'|null $cause  null ⇒ no pressure.
     * @param 'mkdir'|'open'|'write'|'flush'|'close'|null $probeStage
     *        Which stage of the write probe failed; null when `$cause`
     *        is not `write_probe`.
     * @param string|null $probeError   Truncated `error_get_last()` message.
     * @param string|null $probePath    Set iff the write probe ran. NOT
     *                                  emitted on the event — it exists
     *                                  so the per-probe-unique filename
     *                                  is testable, and to help an
     *                                  operator chase a leaked file.
     */
    public function __construct(
        public readonly string $partition,
        public readonly ?float $freePct,
        public readonly float $thresholdPct,
        public readonly int $probeBytes = 0,
        public readonly ?string $cause = null,
        public readonly ?string $probeStage = null,
        public readonly ?string $probeError = null,
        public readonly ?string $probePath = null,
    ) {
    }

    public function shouldSkipClaim(): bool
    {
        return $this->cause !== null;
    }
}
