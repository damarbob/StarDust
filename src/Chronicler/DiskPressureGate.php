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
 * throttling. Each Chronicler tick calls {@see self::sample()} exactly
 * once, which probes the OS exactly once — so a transient pressure
 * spike does not stick after the underlying filesystem recovers, and
 * (unlike an earlier version of this class) the `low_disk` event's
 * `free_pct` can never disagree with the value that actually decided
 * `shouldSkipClaim()`, because both come off the same
 * {@see DiskPressureReading}.
 *
 * The probe is always taken against `artifactDir` itself, never a
 * fallback directory. `ArtifactStreamFactory::ensureArtifactDir()`
 * creates `artifactDir` lazily on first claim, so on a fresh install
 * (or between exports) the directory may not exist yet; `is_dir()`
 * returning false is treated identically to `disk_free_space()`
 * failing — a `null` reading, i.e. fail OPEN — rather than silently
 * probing a different, unrelated directory (an earlier version of
 * this class fell back to `sys_get_temp_dir()` here, which meant a
 * `low_disk` event's reported `partition` could name a directory that
 * was never the one measured). The processor's later `fwrite()` will
 * surface a real problem with a typed exception regardless.
 *
 * **This does not yet see a per-account disk quota** — it reports
 * partition-level free space only, and shared hosting commonly
 * enforces a quota above the filesystem layer this gate checks. That
 * gap is tracked as open build-sequencing work, not resolved here.
 */
final class DiskPressureGate
{
    public function __construct(
        private readonly string $artifactDir,
        private readonly float $lowDiskThresholdPct,
    ) {
    }

    public function sample(): DiskPressureReading
    {
        return new DiskPressureReading(
            partition: $this->artifactDir,
            freePct: $this->probeFreePct(),
            thresholdPct: $this->lowDiskThresholdPct,
        );
    }

    private function probeFreePct(): ?float
    {
        if (!is_dir($this->artifactDir)) {
            return null;
        }
        $free  = @disk_free_space($this->artifactDir);
        $total = @disk_total_space($this->artifactDir);
        if ($free === false || $total === false || $total <= 0.0) {
            return null;
        }
        return $free / $total;
    }
}
