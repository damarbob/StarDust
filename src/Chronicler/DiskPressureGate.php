<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

/**
 * Pre-claim disk-space gate. Wraps `disk_free_space()` against the
 * configured artifact directory and reports whether new export claims
 * should be skipped (chronicler_daemon.md §2: 10% free threshold by
 * default; in-flight jobs are unaffected — they continue until the
 * stream raises {@see \StarDust\Exception\ChroniclerArtifactDiskFullException}).
 *
 * The gate is intentionally simple: no caching, no probe frequency
 * throttling. Each Chronicler tick re-probes via the OS so a transient
 * pressure spike does not stick after the underlying filesystem
 * recovers.
 *
 * If `disk_free_space()` fails (returns false — e.g., the directory
 * does not yet exist or is unreadable) the gate fails OPEN to allow
 * the claim. The processor's later `fwrite()` will surface the real
 * problem with a typed exception.
 */
final class DiskPressureGate
{
    public function __construct(
        private readonly string $artifactDir,
        private readonly float $lowDiskThresholdPct,
    ) {
    }

    public function shouldSkipClaim(): bool
    {
        $pct = $this->probeFreePct();
        return $pct !== null && $pct < $this->lowDiskThresholdPct;
    }

    /**
     * Free-space ratio in `[0, 1]`, or `null` when the probe is
     * unavailable (caller treats null as "no pressure detected").
     */
    public function freePct(): ?float
    {
        return $this->probeFreePct();
    }

    public function partition(): string
    {
        return $this->artifactDir;
    }

    public function thresholdPct(): float
    {
        return $this->lowDiskThresholdPct;
    }

    private function probeFreePct(): ?float
    {
        $probeDir = is_dir($this->artifactDir) ? $this->artifactDir : sys_get_temp_dir();
        $free  = @disk_free_space($probeDir);
        $total = @disk_total_space($probeDir);
        if ($free === false || $total === false || $total <= 0.0) {
            return null;
        }
        return $free / $total;
    }
}
