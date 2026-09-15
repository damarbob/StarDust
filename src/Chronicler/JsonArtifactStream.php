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
 *
 * Resumption (ADR 0047): with a nonzero `$resumeBytes` anchor, `open()`
 * re-opens `$resumePath` (`'c+b'`, no truncate), acquires an exclusive
 * non-blocking lock, and verifies the file EXISTS, holds at least
 * `$resumeBytes` bytes, AND begins with `[` — JSON has no header to
 * compare, so the leading-bracket check is the whole prelude
 * verification. On success it truncates to exactly `$resumeBytes`,
 * seeks there, and sets `$firstRowEmitted = true` so the next
 * `appendRow()` writes the `,` prefix a mid-array resume needs. On ANY
 * verification failure it opens `$freshPath` instead — a DIFFERENT
 * file, never `$resumePath` — for the same reason as
 * {@see CsvArtifactStream}: a `locked` rejection can mean another
 * process is still actively writing `$resumePath`. `path()` reports
 * whichever path ended up in use.
 */
final class JsonArtifactStream implements ArtifactStream
{
    /** @var resource|null */
    private $handle = null;
    private int $bytesWritten = 0;
    private bool $firstRowEmitted = false;
    private int $resumedFromByte = 0;
    private ?string $restartCause = null;
    private string $activePath;

    /**
     * @param string $freshPath Always used when there is no anchor, or
     *   the anchor fails verification. Never the same file as
     *   `$resumePath`.
     * @param string|null $resumePath The prior attempt's artifact path
     *   to try adopting. `null` means no anchor — always use `$freshPath`.
     * @param int|null $resumeBytes ADR 0047 resume anchor: the byte
     *   count a prior attempt is believed to have committed to
     *   `$resumePath`. `null` (or `0`) means no anchor.
     */
    public function __construct(
        private readonly string $freshPath,
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

        if ($this->resumePath === null || $this->resumeBytes === null || $this->resumeBytes <= 0) {
            $this->openFresh('no_anchor');
            return;
        }

        // 'c+b' would CREATE a missing file rather than failing —
        // check existence explicitly (see CsvArtifactStream::open()).
        if (!@file_exists($this->resumePath)) {
            $this->openFresh('missing');
            return;
        }

        $h = @fopen($this->resumePath, 'c+b');
        if ($h === false) {
            $this->openFresh('missing');
            return;
        }

        if (!@flock($h, LOCK_EX | LOCK_NB)) {
            @fclose($h);
            $this->openFresh('locked');
            return;
        }

        $stat = @fstat($h);
        $size = $stat !== false ? (int) $stat['size'] : 0;
        if ($size < $this->resumeBytes) {
            @flock($h, LOCK_UN);
            @fclose($h);
            $this->openFresh('short');
            return;
        }

        fseek($h, 0);
        $firstByte = fread($h, 1);
        if ($firstByte !== '[') {
            @flock($h, LOCK_UN);
            @fclose($h);
            $this->openFresh('header_mismatch');
            return;
        }

        if (!@ftruncate($h, $this->resumeBytes) || fseek($h, $this->resumeBytes) !== 0) {
            @flock($h, LOCK_UN);
            @fclose($h);
            $this->openFresh('short');
            return;
        }

        $this->handle           = $h;
        $this->activePath       = $this->resumePath;
        $this->bytesWritten     = $this->resumeBytes;
        $this->resumedFromByte  = $this->resumeBytes;
        $this->restartCause     = null;
        // Byte 0 is always the leading `[` (verified above), so
        // resumeBytes === 1 means no row has ever been successfully
        // appended — a non-final chunk whose every row hit an encoding
        // error commits exactly this state. Anything past byte 1 means
        // at least one row landed, so the next appendRow() needs the
        // `,` separator rather than a bare first-element write.
        $this->firstRowEmitted = $this->resumeBytes > 1;
    }

    public function resumedFromByte(): int
    {
        return $this->resumedFromByte;
    }

    public function restartCause(): ?string
    {
        return $this->restartCause;
    }

    private function openFresh(string $cause): void
    {
        $h = @fopen($this->freshPath, 'wb');
        if ($h === false) {
            throw new RuntimeException("JsonArtifactStream: cannot open '{$this->freshPath}' for write.");
        }
        @flock($h, LOCK_EX | LOCK_NB);
        $this->handle          = $h;
        $this->activePath      = $this->freshPath;
        $this->bytesWritten    = 0;
        $this->resumedFromByte = 0;
        $this->restartCause    = $cause;
        $this->firstRowEmitted = false;
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

    public function flush(): void
    {
        if ($this->handle !== null) {
            @fflush($this->handle);
        }
    }

    public function close(): void
    {
        // Held in a local: the writeRaw() call below would otherwise
        // invalidate any narrowing of the property.
        $handle = $this->handle;
        if ($handle === null) {
            return;
        }
        $this->writeRaw(']');
        @fflush($handle);
        @flock($handle, LOCK_UN);
        @fclose($handle);
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

    private function writeRaw(string $bytes): void
    {
        $handle = $this->handle;
        if ($handle === null) {
            // See CsvArtifactStream::writeRaw() — unreachable today because
            // every caller narrows first, but this is the only place fwrite()
            // itself is protected, and a programming error here is not the
            // disk-full condition the processor knows how to handle.
            throw new RuntimeException(
                "JSON artifact stream at '{$this->activePath}' is not open; call open() first."
            );
        }

        $expected = strlen($bytes);
        $written = @fwrite($handle, $bytes);
        if ($written === false || $written !== $expected) {
            throw new ChroniclerArtifactDiskFullException(
                "JSON artifact write truncated at '{$this->activePath}'; "
                . "expected={$expected}, written=" . var_export($written, true) . '.'
            );
        }
        $this->bytesWritten += $written;
    }
}
