<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use JsonException;
use RuntimeException;
use StarDust\Exception\ChroniclerArtifactDiskFullException;
use StarDust\Exception\ChroniclerRowEncodingException;

/**
 * Single-document JSON array streaming writer.
 *
 * Format: `[<obj1>,<obj2>,...]`. The leading `[` is written by
 * `open()`; the trailing `]` by `close()`. Each `appendRow()` writes a
 * leading `,` for every row after the first. Each row is a JSON
 * object whose keys are the row's `fields` keys; the Chronicler does
 * not project against the header list — JSON callers expect the full
 * payload per ADR 0013.
 *
 * Symmetric with ADR 0028 (single-document JSON imports). NDJSON was
 * considered and rejected for that symmetry — round-tripping an
 * exported artifact through the import path with no transform is a
 * non-goal today but the format choice preserves the option.
 *
 * Encoding failures:
 *   - `json_encode` raises `JsonException` (lone surrogate, malformed
 *     UTF-8, etc.) → {@see ChroniclerRowEncodingException} with reason
 *     `unrepresentable_codepoint`. PHP's encoder is strict on UTF-8.
 *   - Short `fwrite` →
 *     {@see ChroniclerArtifactDiskFullException}.
 */
final class JsonArtifactStream implements ArtifactStream
{
    /** @var resource|null */
    private $handle = null;
    private int $bytesWritten = 0;
    private bool $firstRowEmitted = false;

    public function __construct(private readonly string $path)
    {
    }

    public function open(): void
    {
        if ($this->handle !== null) {
            return;
        }
        $h = @fopen($this->path, 'wb');
        if ($h === false) {
            throw new RuntimeException("JsonArtifactStream: cannot open '{$this->path}' for write.");
        }
        $this->handle = $h;
        $this->writeRaw('[');
    }

    public function appendRow(EntryDataRow $row): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('JsonArtifactStream::appendRow before open().');
        }

        try {
            $encoded = json_encode(
                $row->fields,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $e) {
            throw new ChroniclerRowEncodingException(
                ChroniclerRowEncodingException::REASON_UNREPRESENTABLE_CODEPOINT,
                'JSON row encode failed: ' . $e->getMessage()
            );
        }

        $payload = $this->firstRowEmitted ? ',' . $encoded : $encoded;
        $this->writeRaw($payload);
        $this->firstRowEmitted = true;
    }

    public function bytesWritten(): int
    {
        return $this->bytesWritten;
    }

    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }
        $this->writeRaw(']');
        @fflush($this->handle);
        @fclose($this->handle);
        $this->handle = null;
    }

    public function delete(): void
    {
        if ($this->handle !== null) {
            @fclose($this->handle);
            $this->handle = null;
        }
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    private function writeRaw(string $bytes): void
    {
        $expected = strlen($bytes);
        $written = @fwrite($this->handle, $bytes);
        if ($written === false || $written !== $expected) {
            throw new ChroniclerArtifactDiskFullException(
                "JSON artifact write truncated at '{$this->path}'; "
                . "expected={$expected}, written=" . var_export($written, true) . '.'
            );
        }
        $this->bytesWritten += $written;
    }
}
