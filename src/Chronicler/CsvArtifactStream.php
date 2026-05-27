<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use RuntimeException;
use StarDust\Exception\ChroniclerArtifactDiskFullException;
use StarDust\Exception\ChroniclerRowEncodingException;

/**
 * RFC 4180 streaming CSV writer.
 *
 * Header row is written by `open()` and is the alphabetically-sorted
 * union of `stardust_fields.name` for the job's `(tenant_id, model_id)`
 * (resolved upstream by {@see HeaderResolver}). The header is stable
 * across re-claims, which matters for any downstream consumer that
 * appends to or concatenates artifacts.
 *
 * Quoting rules per RFC 4180:
 *   - Wrap a field in `"` when it contains `,`, `"`, `\r`, or `\n`.
 *   - Escape embedded `"` by doubling (`""`).
 *   - Line terminator is `\r\n`.
 *
 * Encoding failures:
 *   - Embedded NUL byte → {@see ChroniclerRowEncodingException} with
 *     reason `format_invalid`. CSV cannot reliably round-trip NULs
 *     and they typically signal binary data being written into a text
 *     export, which is the operator's bug.
 *   - Short `fwrite()` (typically ENOSPC) →
 *     {@see ChroniclerArtifactDiskFullException}.
 *
 * Missing fields (rows whose JSON payload lacks one of the header
 * columns) write an empty cell — operator-friendly, matches
 * spreadsheet conventions, and never raises an exception.
 *
 * Resumption (re-claim from `last_cursor`) starts a fresh artifact
 * file — abandoned-claim sweep best-effort-deletes the prior partial
 * before this stream's `open()` runs. The header is therefore
 * re-emitted, which is correct for an artifact that will be consumed
 * as a standalone file.
 */
final class CsvArtifactStream implements ArtifactStream
{
    /** @var resource|null */
    private $handle = null;
    private int $bytesWritten = 0;
    private const NEWLINE = "\r\n";

    /**
     * @param list<string> $headerFields Alphabetically-sorted field names.
     */
    public function __construct(
        private readonly string $path,
        private readonly array $headerFields,
    ) {
    }

    public function open(): void
    {
        if ($this->handle !== null) {
            return;
        }
        $h = @fopen($this->path, 'wb');
        if ($h === false) {
            throw new RuntimeException("CsvArtifactStream: cannot open '{$this->path}' for write.");
        }
        $this->handle = $h;

        if ($this->headerFields !== []) {
            $header = implode(',', array_map([$this, 'encodeField'], $this->headerFields));
            $this->writeRaw($header . self::NEWLINE);
        }
    }

    public function appendRow(EntryDataRow $row): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('CsvArtifactStream::appendRow before open().');
        }

        $cells = [];
        foreach ($this->headerFields as $name) {
            $value = $row->fields[$name] ?? null;
            $cells[] = $this->encodeField($this->stringify($value));
        }
        $line = implode(',', $cells) . self::NEWLINE;
        $this->writeRaw($line);
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

    /**
     * Encode one cell value per RFC 4180. Embedded NUL → typed
     * encoding exception so the processor can charge skip_count and
     * keep going.
     */
    private function encodeField(string $value): string
    {
        if (strpos($value, "\0") !== false) {
            throw new ChroniclerRowEncodingException(
                ChroniclerRowEncodingException::REASON_FORMAT_INVALID,
                'CSV cannot encode an embedded NUL byte.'
            );
        }
        $needsQuoting = strpbrk($value, ",\"\r\n") !== false;
        if (!$needsQuoting) {
            return $value;
        }
        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * Coerce JSON-decoded values to strings for CSV. Scalar coercion
     * is straightforward; arrays / objects are JSON-encoded so the
     * cell is at least machine-parseable rather than `Array`.
     */
    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $value;
        }
        // Arrays / objects: encode as JSON so the cell remains
        // round-trippable. JsonException here is exceptional (the
        // payload came from MySQL JSON which round-trips fine);
        // promote to format_invalid rather than crash the worker.
        try {
            return (string) json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (\JsonException $e) {
            throw new ChroniclerRowEncodingException(
                ChroniclerRowEncodingException::REASON_FORMAT_INVALID,
                'CSV cell value failed json_encode: ' . $e->getMessage()
            );
        }
    }

    private function writeRaw(string $bytes): void
    {
        $expected = strlen($bytes);
        $written = @fwrite($this->handle, $bytes);
        if ($written === false || $written !== $expected) {
            // Short write or false: treat as disk-full. The processor
            // catches this and marks the job failed:disk_full.
            throw new ChroniclerArtifactDiskFullException(
                "CSV artifact write truncated at '{$this->path}'; "
                . "expected={$expected}, written=" . var_export($written, true) . '.'
            );
        }
        $this->bytesWritten += $written;
    }
}
