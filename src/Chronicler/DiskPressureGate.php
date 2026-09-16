<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use StarDust\Support\ArtifactDirectory;
use StarDust\Support\UuidV4;

/**
 * Pre-claim disk gate (chronicler_daemon.md §2, ADR 0051). Answers
 * "can the Chronicler commit to writing an artifact right now?" and,
 * when it cannot, skips claiming new work. In-flight jobs are
 * unaffected — they continue until the stream raises
 * {@see \StarDust\Exception\ChroniclerArtifactDiskFullException}.
 *
 * ## Two checks, ordered and short-circuiting
 *
 * 1. **Free-space ratio** against `artifactDir` — the cheap pre-filter.
 * 2. **Write probe** — actually write `probeBytes` into `artifactDir`
 *    and delete them again.
 *
 * A ratio trip returns IMMEDIATELY and the write probe never runs.
 * That is deliberate on two counts: it makes `cause` a clean partition
 * (`write_probe` means the ratio passed *and* the probe failed, so
 * there is no precedence rule and no `both`), and a nearly-full
 * partition is precisely when you least want to burn an extra write.
 *
 * ## Why a write probe rather than a second threshold
 *
 * Measured on kernel 6.18.33.2 (ADR 0051): with an **ext4 per-uid
 * quota** 1 MiB from its cap, `disk_free_space()` reported the
 * filesystem **91.2% free** while every `fwrite()` failed with
 * `EDQUOT`. No threshold on a number that does not move can detect
 * that wall. Shared hosting assigns each account a uid and enforces
 * exactly this kind of quota, so the blind case is the common one.
 *
 * Project quotas are NOT blind — both ext4 `prjquota` and XFS
 * `pquota` scope `statvfs` to the project tree, which is why the
 * ratio check is retained rather than replaced.
 *
 * `probeBytes` is a **detection-sensitivity parameter, not a reserve
 * budget**: it asks "can I write this much right now", and is
 * deliberately not derived from `chroniclerPageSize` or from expected
 * artifact size (rows are variable-width, so a derived number would
 * be false precision). `0` disables the probe entirely and restores
 * the pre-0051 ratio-only gate, including taking no side effect on
 * the filesystem.
 *
 * ## Fails CLOSED, unlike the ratio check
 *
 * Every probe failure stage — including a permissions error — skips
 * the claim. The alternative is not "the job fails cleanly": today
 * `ExportJobProcessor` builds its stream OUTSIDE any try block, so an
 * unwritable directory claims the job, flips it to `processing`, then
 * throws out through `tickRound()` and kills the process; the lease
 * expires and the next worker dies identically. Under
 * `bin/stardust tick --exports` that also truncates the whole
 * combined run (ADR 0048's one-failure-domain rule). A stalled queue
 * with a per-tick `low_disk` warning is strictly better than a crash
 * loop.
 *
 * ## No caching, one probe per tick
 *
 * Each Chronicler tick calls {@see self::sample()} exactly once, which
 * probes exactly once — so a transient spike does not stick after the
 * filesystem recovers, and the `low_disk` event's `free_pct` can never
 * disagree with the value that decided to skip, because both come off
 * the same {@see DiskPressureReading}. The reading is never stored on
 * the gate.
 *
 * ## Traps
 *
 * - **The probe filename is unique per probe, and that is load-bearing
 *   for multi-worker safety.** Two `bin/stardust chronicler` processes
 *   share one `artifactDir`; with a fixed name, worker A's cleanup
 *   unlink deletes worker B's in-flight probe file, and on POSIX B
 *   then writes to an unlinked inode and *passes wrongly*.
 * - **Never implement the probe with `ftruncate()`.** That creates a
 *   sparse file: no block allocation, so no quota charge, so the probe
 *   would pass under the exact condition it exists to detect.
 * - `fsync()` is deliberately NOT called. Measured across all four
 *   ADR 0051 arms, `EDQUOT`/`ENOSPC` surfaces at `write(2)` itself;
 *   `fsync` would cost a real disk sync every tick forever and buy
 *   nothing. `fflush()` IS checked because it is free.
 */
final class DiskPressureGate
{
    public const PROBE_PREFIX = '.stardust-diskprobe-';
    public const PROBE_SUFFIX = '.tmp';

    private readonly ArtifactDirectory $dir;

    public function __construct(
        string $artifactDir,
        private readonly float $lowDiskThresholdPct,
        private readonly int $probeBytes = 0,
    ) {
        $this->dir = new ArtifactDirectory($artifactDir);
    }

    /**
     * The glob pattern matching this gate's probe files in a given
     * directory. Public so {@see GcSweeper} can sweep leaked probes
     * without duplicating the literals. The leading dot keeps probe
     * files out of any `artifactDir/*` artifact listing while staying
     * reachable by this explicit pattern.
     */
    public static function probeGlob(string $artifactDir): string
    {
        return rtrim($artifactDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . self::PROBE_PREFIX . '*' . self::PROBE_SUFFIX;
    }

    public function sample(): DiskPressureReading
    {
        $path = $this->dir->path();

        // With the probe disabled the gate stays a pure read — a true,
        // complete opt-out that creates nothing on the filesystem.
        $dirReady = $this->probeBytes > 0 ? $this->dir->ensure() : is_dir($path);

        // 1. Ratio pre-filter, ALWAYS against artifactDir itself. A
        //    missing directory yields null (fail open), never a
        //    reading from some other directory — an earlier version
        //    fell back to sys_get_temp_dir() here, which meant a
        //    low_disk event's `partition` could name a directory that
        //    was never the one measured.
        $freePct = $this->probeFreePct();
        if ($freePct !== null && $freePct < $this->lowDiskThresholdPct) {
            return new DiskPressureReading(
                partition: $path,
                freePct: $freePct,
                thresholdPct: $this->lowDiskThresholdPct,
                probeBytes: $this->probeBytes,
                cause: 'free_pct',
            );
        }

        if ($this->probeBytes === 0) {
            return new DiskPressureReading(
                partition: $path,
                freePct: $freePct,
                thresholdPct: $this->lowDiskThresholdPct,
            );
        }

        if (!$dirReady) {
            return new DiskPressureReading(
                partition: $path,
                freePct: $freePct,
                thresholdPct: $this->lowDiskThresholdPct,
                probeBytes: $this->probeBytes,
                cause: 'write_probe',
                probeStage: 'mkdir',
                probeError: $this->lastErrorMessage(),
            );
        }

        // 2. Write probe.
        $probePath = rtrim($path, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . self::PROBE_PREFIX . UuidV4::generate() . self::PROBE_SUFFIX;

        $stage = $this->runWriteProbe($probePath, $error);

        return new DiskPressureReading(
            partition: $path,
            freePct: $freePct,
            thresholdPct: $this->lowDiskThresholdPct,
            probeBytes: $this->probeBytes,
            cause: $stage === null ? null : 'write_probe',
            probeStage: $stage,
            probeError: $stage === null ? null : $error,
            probePath: $probePath,
        );
    }

    /**
     * Runs the probe, always cleaning up after itself.
     *
     * @param  string|null $error  Out-param: the failing call's message.
     * @return 'open'|'write'|'flush'|'close'|null  The failing stage,
     *         or null when the probe succeeded.
     */
    private function runWriteProbe(string $probePath, ?string &$error): ?string
    {
        $error  = null;
        $handle = null;

        try {
            error_clear_last();
            $opened = @fopen($probePath, 'wb');
            if ($opened === false) {
                $error = $this->lastErrorMessage();
                return 'open';
            }
            $handle = $opened;

            // One fwrite() == one write(2): removes PHP's userland
            // buffer as a variable so the probe observes the kernel's
            // answer, not its own buffering.
            //
            // Best-effort and suppressed: a custom stream wrapper
            // under `artifactDir` need not implement
            // `stream_set_option`, and PHP warns when it doesn't. The
            // probe is still correct without it — ADR 0051 measured
            // buffered and unbuffered runs across all four arms and
            // got identical results.
            @stream_set_write_buffer($handle, 0);

            error_clear_last();
            $written = @fwrite($handle, str_repeat("\0", $this->probeBytes));
            if ($written === false || $written !== $this->probeBytes) {
                $error = $this->lastErrorMessage();
                return 'write';
            }

            error_clear_last();
            if (@fflush($handle) === false) {
                $error = $this->lastErrorMessage();
                return 'flush';
            }

            error_clear_last();
            $closed = @fclose($handle);
            $handle = null;
            if ($closed === false) {
                $error = $this->lastErrorMessage();
                return 'close';
            }

            return null;
        } finally {
            if ($handle !== null) {
                @fclose($handle);
            }
            // A failed unlink is NOT a gate failure — the probe has
            // already answered the question, and GcSweeper is the
            // backstop for a file we could not remove.
            @unlink($probePath);
        }
    }

    private function lastErrorMessage(): ?string
    {
        $last = error_get_last();
        if ($last === null) {
            return null;
        }
        $message = $last['message'];
        return strlen($message) > 200 ? substr($message, 0, 200) : $message;
    }

    private function probeFreePct(): ?float
    {
        $path = $this->dir->path();
        if (!is_dir($path)) {
            return null;
        }
        $free  = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0.0) {
            return null;
        }
        return $free / $total;
    }
}
