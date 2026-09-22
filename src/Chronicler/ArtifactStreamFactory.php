<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use RuntimeException;
use StarDust\Support\ArtifactDirectory;
use StarDust\Support\UuidV4;

/**
 * Single dispatch point for `$job->format` → concrete {@see ArtifactStream}.
 *
 * ALWAYS mints a fresh fallback filename —
 * `<artifactDir>/export_<jobId>_<uuid>.<csv|json>` — even when the
 * claim carries a resume anchor. This is deliberate, not a leftover:
 * per ADR 0047, `open()` tries the anchor path first, but falls back
 * to the fresh path (never back to the anchor path) on ANY
 * verification failure — including `locked`, where another process
 * may still be actively writing the anchor file. Reusing the
 * contested path there would risk two writers on one file; the fresh
 * path never has that risk, so every fallback cause uses it uniformly
 * rather than special-casing `locked` alone. The anchor stays on disk
 * untouched, orphaned for GC or forensics.
 *
 * With an anchor (an abandoned claim whose row carried `artifact_path`
 * / `artifact_bytes`, per ADR 0047), the stream is constructed with
 * both paths so `open()` can attempt to re-open and adopt the anchor
 * in place. Whether adoption actually succeeds is the stream's call,
 * not this factory's — see {@see ArtifactStream::open()} and
 * {@see ArtifactStream::path()}, which reports whichever path ended up
 * in use.
 *
 * Creating the artifact directory on demand (recursive `mkdir`) is
 * symmetric with {@see \StarDust\Write\BulkIngestSubmitter}; Config
 * itself stays side-effect-free.
 */
final class ArtifactStreamFactory
{
    public function __construct(private readonly string $artifactDir)
    {
    }

    /**
     * @param list<string> $headerFields Header column list resolved by
     *   {@see HeaderResolver}. Only consumed by the CSV stream; JSON
     *   ignores it and emits the full row payload per ADR 0013.
     * @param array<string,string> $renameAliases ADR 0036 current →
     *   pre-rename names. Also CSV-only, and for the same reason: CSV
     *   projects the payload against a fixed header, so a stale key
     *   becomes a blank cell under a correct column. JSON emits the
     *   payload verbatim, so a consumer sees the old key and can cope —
     *   that asymmetry is deliberate.
     */
    public function from(ClaimedJob $job, array $headerFields, array $renameAliases = []): ArtifactStream
    {
        $this->ensureArtifactDir();

        $ext = match ($job->format) {
            'csv'  => 'csv',
            'json' => 'json',
            default => throw new RuntimeException(
                "ArtifactStreamFactory: unsupported format '{$job->format}' on job {$job->id}."
            ),
        };

        $freshPath = rtrim($this->artifactDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . "export_{$job->id}_" . UuidV4::generate() . '.' . $ext;

        // A non-null artifactPath with no usable byte count (e.g. a
        // pre-0047 row) has nothing to verify against — treat as no
        // anchor at all.
        $resumePath  = $job->artifactPath;
        $resumeBytes = ($resumePath !== null && $job->artifactBytes !== null && $job->artifactBytes > 0)
            ? $job->artifactBytes
            : null;
        if ($resumeBytes === null) {
            $resumePath = null;
        }

        return match ($job->format) {
            'csv'  => new CsvArtifactStream($freshPath, $headerFields, $renameAliases, $resumePath, $resumeBytes),
            'json' => new JsonArtifactStream($freshPath, $resumePath, $resumeBytes),
        };
    }

    private function ensureArtifactDir(): void
    {
        if (!(new ArtifactDirectory($this->artifactDir))->ensure()) {
            throw new RuntimeException(
                "ArtifactStreamFactory: artifact directory '{$this->artifactDir}' is not writable."
            );
        }
    }
}
