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
 * Resumption (ADR 0047): with a nonzero `$resumeBytes` anchor, `open()`
 * re-opens `$resumePath` (`'c+b'`, no truncate), acquires an exclusive
 * non-blocking lock, and verifies the file EXISTS, holds at least
 * `$resumeBytes` bytes, AND that its first line equals the header this
 * stream would write today (catching a field renamed between the dead
 * worker's attempt and this one — `stardust_fields` flips a rename
 * immediately per ADR 0036, while the file on disk was written under
 * the old name). On success it truncates to exactly `$resumeBytes`,
 * seeks there, and skips re-writing the header — the header is stable
 * across a genuine resume, which matters for any downstream consumer
 * that appends to or concatenates artifacts. On ANY verification
 * failure (missing file, short file, header mismatch, lock
 * unavailable) it opens `$freshPath` instead — a DIFFERENT file, never
 * `$resumePath` — because a `locked` rejection specifically can mean
 * another process is still actively writing `$resumePath`, and
 * reusing that exact path would risk two writers on one file.
 * `path()` reports whichever path ended up in use.
 */
final class CsvArtifactStream implements ArtifactStream
{
    /** @var resource|null */
    private $handle = null;
    private int $bytesWritten = 0;
    private int $resumedFromByte = 0;
    private ?string $restartCause = null;
    private string $activePath;
    private const NEWLINE = "\r\n";

    /**
     * @param string $freshPath Always used when there is no anchor, or
     *   the anchor fails verification. Never the same file as
     *   `$resumePath`.
     * @param list<string> $headerFields Alphabetically-sorted field names.
     * @param array<string,string> $renameAliases ADR 0036: current name →
     *   pre-rename name, for fields whose backfill is still draining.
     *   Empty in steady state.
     * @param string|null $resumePath The prior attempt's artifact path
     *   to try adopting. `null` means no anchor — always use `$freshPath`.
     * @param int|null $resumeBytes ADR 0047 resume anchor: the byte
     *   count a prior attempt is believed to have committed to
     *   `$resumePath`. `null` (or `0`) means no anchor.
     */
    public function __construct(
        private readonly string $freshPath,
        private readonly array $headerFields,
        private readonly array $renameAliases = [],
        private readonly ?string $resumePath = null,
        private readonly ?int $resumeBytes = null,
    ) {
        $this->activePath = $freshPath;
    }

    public function open(): void
    {
        if ($this->handle !== null) {
            return;
        }

        $header = $this->headerFields !== []
            ? implode(',', array_map([$this, 'encodeField'], $this->headerFields)) . self::NEWLINE
            : '';

        if ($this->resumePath === null || $this->resumeBytes === null || $this->resumeBytes <= 0) {
            $this->openFresh($header, 'no_anchor');
            return;
        }

        // 'c+b' would CREATE a missing file rather than failing —
        // check existence explicitly so a genuinely missing anchor
        // reports 'missing' rather than being masked as a 0-byte
        // 'short' file.
        if (!@file_exists($this->resumePath)) {
            $this->openFresh($header, 'missing');
            return;
        }

        $h = @fopen($this->resumePath, 'c+b');
        if ($h === false) {
            $this->openFresh($header, 'missing');
            return;
        }

        if (!@flock($h, LOCK_EX | LOCK_NB)) {
            @fclose($h);
            $this->openFresh($header, 'locked');
            return;
        }

        $stat = @fstat($h);
        $size = $stat !== false ? (int) $stat['size'] : 0;
        if ($size < $this->resumeBytes) {
            @flock($h, LOCK_UN);
            @fclose($h);
            $this->openFresh($header, 'short');
            return;
        }

        if ($header !== '') {
            $firstLine = $this->readFirstLine($h);
            if ($firstLine !== rtrim($header, self::NEWLINE)) {
                @flock($h, LOCK_UN);
                @fclose($h);
                $this->openFresh($header, 'header_mismatch');
                return;
            }
        }

        if (!@ftruncate($h, $this->resumeBytes) || fseek($h, $this->resumeBytes) !== 0) {
            @flock($h, LOCK_UN);
            @fclose($h);
            $this->openFresh($header, 'short');
            return;
        }

        $this->handle           = $h;
        $this->activePath       = $this->resumePath;
        $this->bytesWritten     = $this->resumeBytes;
        $this->resumedFromByte  = $this->resumeBytes;
        $this->restartCause     = null;
    }

    public function resumedFromByte(): int
    {
        return $this->resumedFromByte;
    }

    public function restartCause(): ?string
    {
        return $this->restartCause;
    }

    /**
     * Opens `$freshPath` — a file no other worker's anchor points at —
     * shared by "no anchor was supplied" and every anchor-verification
     * failure. `$cause` is always the closed-taxonomy reason for the
     * fallback — see {@see ArtifactStream::restartCause()}.
     */
    private function openFresh(string $header, string $cause): void
    {
        $h = @fopen($this->freshPath, 'wb');
        if ($h === false) {
            throw new RuntimeException("CsvArtifactStream: cannot open '{$this->freshPath}' for write.");
        }
        // Best-effort — a fresh 'wb' file has no contention to defend
        // against, but holding the lock keeps behavior uniform with the
        // resumed path for the remainder of this stream's lifetime.
        @flock($h, LOCK_EX | LOCK_NB);
        $this->handle           = $h;
        $this->activePath       = $this->freshPath;
        $this->bytesWritten     = 0;
        $this->resumedFromByte  = 0;
        $this->restartCause     = $cause;
        if ($header !== '') {
            $this->writeRaw($header);
        }
    }

    /**
     * Reads the first line (up to and including the first `\n`, if
     * any) without disturbing the handle's later use — the caller
     * still needs to `ftruncate`/`fseek` afterward. Returns the line
     * with the trailing CRLF/LF stripped, for a plain string compare
     * against the freshly-resolved header.
     *
     * @param resource $h
     */
    private function readFirstLine($h): string
    {
        fseek($h, 0);
        $line = fgets($h);
        return $line === false ? '' : rtrim($line, "\r\n");
    }

    public function appendRow(EntryDataRow $row): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('CsvArtifactStream::appendRow before open().');
        }

        $cells = [];
        foreach ($this->headerFields as $name) {
            // The header cell always says the current name; only the
            // lookup key falls back. ADR 0036 — see the alias note on
            // HeaderResolver::resolveAliases().
            if (array_key_exists($name, $row->fields)) {
                $value = $row->fields[$name];
            } else {
                $previous = $this->renameAliases[$name] ?? null;
                $value = ($previous !== null && array_key_exists($previous, $row->fields))
                    ? $row->fields[$previous]
                    : null;
            }
            $cells[] = $this->encodeField($this->stringify($value));
        }
        $line = implode(',', $cells) . self::NEWLINE;
        $this->writeRaw($line);
    }

    public function bytesWritten(): int
    {
        return $this->bytesWritten;
    }

    public function flush(): void
    {
        if ($this->handle !== null) {
            @fflush($this->handle);
        }
    }

    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }
        @fflush($this->handle);
        @flock($this->handle, LOCK_UN);
        @fclose($this->handle);
        $this->handle = null;
    }

    public function delete(): void
    {
        if ($this->handle !== null) {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
            $this->handle = null;
        }
        if (is_file($this->activePath)) {
            @unlink($this->activePath);
        }
    }

    public function path(): string
    {
        return $this->activePath;
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
        $handle = $this->handle;
        if ($handle === null) {
            // Unreachable today: every caller narrows first — open() has
            // just assigned the handle, and appendRow() guards above. Kept
            // because narrowing does not cross a method boundary, so this
            // is the only place fwrite() itself is protected, and a future
            // caller added inside this class would otherwise hit a
            // TypeError. Deliberately not ChroniclerArtifactDiskFullException:
            // the processor catches that and marks the job failed:disk_full,
            // which would misreport a programming error as a disk problem.
            throw new RuntimeException(
                "CSV artifact stream at '{$this->activePath}' is not open; call open() first."
            );
        }

        $expected = strlen($bytes);
        $written = @fwrite($handle, $bytes);
        if ($written === false || $written !== $expected) {
            // Short write or false: treat as disk-full. The processor
            // catches this and marks the job failed:disk_full.
            throw new ChroniclerArtifactDiskFullException(
                "CSV artifact write truncated at '{$this->activePath}'; "
                . "expected={$expected}, written=" . var_export($written, true) . '.'
            );
        }
        $this->bytesWritten += $written;
    }
}
