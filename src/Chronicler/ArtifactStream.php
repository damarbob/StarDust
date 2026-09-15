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
 *     `appendRow()`. A stream is always constructed with a fresh
 *     never-before-used path (see {@see ArtifactStreamFactory::from()})
 *     and, optionally, a resume anchor pointing at a DIFFERENT path —
 *     the prior attempt's artifact. When an anchor is present, `open()`
 *     first attempts to verify and re-open it in place per ADR 0047:
 *     acquire an exclusive non-blocking file lock on the anchor path,
 *     confirm the file exists and holds at least the anchored bytes,
 *     and — for a format with a prelude — confirm the prelude still
 *     matches what would be written today. On success it truncates the
 *     ANCHOR file to exactly the anchored byte count, seeks there, and
 *     skips writing a fresh prelude — `path()` reports the anchor path.
 *     On any failure (missing file, short file, prelude mismatch, lock
 *     unavailable) it opens the FRESH path instead — never the
 *     rejected anchor path, since a `locked` rejection specifically
 *     can mean another process is still actively writing it — and
 *     writes the prelude there; `path()` then reports the fresh path.
 *     Either way `resumedFromByte()` and `restartCause()` report which
 *     happened. Without a resume anchor at all, `open()` always uses
 *     the fresh path.
 *   - `appendRow()` may raise {@see ChroniclerRowEncodingException} for
 *     per-row format failures (caller charges `skip_count` and
 *     continues) or {@see ChroniclerArtifactDiskFullException} for
 *     ENOSPC (caller marks the job `failed:disk_full`).
 *   - `bytesWritten()` reports cumulative bytes appended to the file
 *     so the processor can trip the artifact size cap.
 *   - `close()` finalises the format (CSV no-op / JSON `]`), releases
 *     the file lock, and closes the file handle. Idempotent. Called on
 *     lease loss (per ADR 0047) — the artifact is deliberately left on
 *     disk for the re-claimer that now owns the row; deleting it here
 *     would destroy bytes a concurrent worker may already be resuming
 *     from.
 *   - `delete()` removes the partial / completed file from disk
 *     (best-effort), releasing the lock first if held. Idempotent. Used
 *     on terminal failure and GC — **not** on lease loss.
 *   - `path()` returns the absolute path of the artifact file.
 */
interface ArtifactStream
{
    public function open(): void;

    /**
     * The byte offset `open()` actually resumed from — `0` when it
     * started a fresh artifact (no anchor was supplied, or the anchor
     * failed verification). Callers use this rather than the anchor
     * they requested, because only the stream knows whether adoption
     * succeeded. Meaningless before `open()` is called.
     */
    public function resumedFromByte(): int;

    /**
     * `null` on a genuine resume (or when no anchor was ever supplied);
     * otherwise the closed-taxonomy reason a supplied anchor was
     * rejected: `no_anchor` (anchor byte count was zero/absent),
     * `missing` (file not found), `short` (file smaller than the
     * anchor), `header_mismatch` (the prelude no longer matches —
     * a resolved CSV header changed, or a JSON file's first byte isn't
     * `[`), or `locked` (exclusive lock unavailable). Meaningless
     * before `open()` is called.
     */
    public function restartCause(): ?string;

    /**
     * @throws ChroniclerRowEncodingException One-row encoding failure;
     *   caller charges `skip_count` and continues.
     * @throws ChroniclerArtifactDiskFullException Disk-full during
     *   append; caller terminates the job with `failed:disk_full`.
     */
    public function appendRow(EntryDataRow $row): void;

    public function bytesWritten(): int;

    /**
     * Flushes any buffered bytes to the OS without closing the handle.
     * Per ADR 0047, the processor calls this immediately before every
     * chunk-commit transaction writes `artifact_bytes` — the database
     * must never promise bytes the filesystem has not actually taken,
     * or a resumed re-open's `ftruncate`/verification could work
     * against bytes that were only ever buffered in PHP's userland
     * stream buffer. A crash between this flush and the commit is
     * harmless: the file may run slightly ahead of the committed
     * anchor, and the next resume's `ftruncate` trims it back.
     */
    public function flush(): void;

    public function close(): void;

    public function delete(): void;

    public function path(): string;
}
