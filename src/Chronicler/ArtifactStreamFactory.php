<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use RuntimeException;
use StarDust\Support\UuidV4;

/**
 * Single dispatch point for `$job->format` → concrete {@see ArtifactStream}.
 *
 * Generates the on-disk filename as
 * `<artifactDir>/export_<jobId>_<uuid>.<csv|json>` — the uuid suffix
 * prevents collision when an abandoned-claim sweep produces a second
 * artifact for the same job id, leaving the prior partial undisturbed
 * until the re-claimer's best-effort delete runs.
 *
 * Creating the artifact directory on demand (recursive `mkdir`) is
 * symmetric with {@see \StarDust\Write\BulkIngestSubmitter}; Config
 * itself stays side-effect-free per ADR 0026.
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
     */
    public function from(ClaimedJob $job, array $headerFields): ArtifactStream
    {
        $this->ensureArtifactDir();

        $ext = match ($job->format) {
            'csv'  => 'csv',
            'json' => 'json',
            default => throw new RuntimeException(
                "ArtifactStreamFactory: unsupported format '{$job->format}' on job {$job->id}."
            ),
        };

        $path = rtrim($this->artifactDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . "export_{$job->id}_" . UuidV4::generate() . '.' . $ext;

        return match ($job->format) {
            'csv'  => new CsvArtifactStream($path, $headerFields),
            'json' => new JsonArtifactStream($path),
        };
    }

    private function ensureArtifactDir(): void
    {
        if (is_dir($this->artifactDir)) {
            return;
        }
        if (!@mkdir($this->artifactDir, 0o775, true) && !is_dir($this->artifactDir)) {
            throw new RuntimeException(
                "ArtifactStreamFactory: artifact directory '{$this->artifactDir}' is not writable."
            );
        }
    }
}
