<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use StarDust\Exception\ChroniclerArtifactDiskFullException;
use StarDust\Exception\ChroniclerRowEncodingException;

/**
 * Polymorphic streaming writer for export artifacts.
 *
 * The Chronicler exports must materialize incrementally — a multi-GB
 * job cannot buffer the whole payload in PHP heap the way ADR 0028's
 * import path does. Two implementations:
 *   - {@see CsvArtifactStream} — RFC 4180 streaming writer.
 *   - {@see JsonArtifactStream} — single-document JSON array streamed
 *     with leading `[`, comma-separated rows, trailing `]`.
 *
 * Contract:
 *   - `open()` is called exactly once per stream instance, before any
 *     `appendRow()`. It writes any format prelude (CSV header row /
 *     JSON `[`) and prepares the underlying file handle.
 *   - `appendRow()` may raise {@see ChroniclerRowEncodingException} for
 *     per-row format failures (caller charges `skip_count` and
 *     continues) or {@see ChroniclerArtifactDiskFullException} for
 *     ENOSPC (caller marks the job `failed:disk_full`).
 *   - `bytesWritten()` reports cumulative bytes appended to the file
 *     so the processor can trip the artifact size cap.
 *   - `close()` finalises the format (CSV no-op / JSON `]`) and closes
 *     the file handle. Idempotent.
 *   - `delete()` removes the partial / completed file from disk
 *     (best-effort). Idempotent. Used on lease loss, terminal failure,
 *     and GC.
 *   - `path()` returns the absolute path of the artifact file.
 */
interface ArtifactStream
{
    public function open(): void;

    /**
     * @throws ChroniclerRowEncodingException One-row encoding failure;
     *   caller charges `skip_count` and continues.
     * @throws ChroniclerArtifactDiskFullException Disk-full during
     *   append; caller terminates the job with `failed:disk_full`.
     */
    public function appendRow(EntryDataRow $row): void;

    public function bytesWritten(): int;

    public function close(): void;

    public function delete(): void;

    public function path(): string;
}
