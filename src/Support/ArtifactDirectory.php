<?php

declare(strict_types=1);

namespace StarDust\Support;

/**
 * The one definition of "make sure this artifact directory exists,
 * tolerating a concurrent worker that creates it first".
 *
 * Three callers across two packages —
 * {@see \StarDust\Chronicler\ArtifactStreamFactory} (export artifacts),
 * {@see \StarDust\Write\BulkIngestSubmitter} (ADR 0011 async bulk-ingest
 * artifacts), and {@see \StarDust\Chronicler\DiskPressureGate} (the
 * ADR 0051 pre-claim write probe) — each of which had (or would have
 * had) its own inline copy of the `is_dir() || @mkdir() || is_dir()`
 * idiom. **Do not re-inline it**, on the {@see RetryableLockFailure}
 * precedent: the trailing `|| is_dir()` is the race tolerance, and a
 * copy that drops it turns two workers starting together into a
 * spurious failure for whichever one loses the `mkdir`.
 *
 * Config itself stays side-effect-free, which is why directory
 * creation lives here and happens on first use rather than at
 * construction.
 *
 * **Returns `bool` and deliberately does not throw.** What a caller
 * does with `false` is caller policy, exactly as
 * {@see RetryableLockFailure} declines to standardise what happens
 * after a match: `ArtifactStreamFactory` and `BulkIngestSubmitter`
 * each raise their own `RuntimeException` with their own message,
 * while `DiskPressureGate` turns it into a `probe_stage: 'mkdir'`
 * gate trip and never throws at all.
 */
final class ArtifactDirectory
{
    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * True when the directory exists or was created by this call.
     *
     * The trailing `is_dir()` re-check is what makes this safe under
     * concurrency: a second worker whose `mkdir` lost the race sees
     * `false` from `mkdir` but `true` from the re-check, which is
     * success, not failure.
     */
    public function ensure(): bool
    {
        if (is_dir($this->path)) {
            return true;
        }
        return @mkdir($this->path, 0o775, true) || is_dir($this->path);
    }
}
