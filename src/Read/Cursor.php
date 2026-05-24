<?php

declare(strict_types=1);

namespace StarDust\Read;

/**
 * Opaque cursor handed to consumers as the next-page token.
 *
 * Per ADR 0006 the cursor's internal format is engine-private and
 * the consumer's only contract is "pass it back unchanged." The
 * actual encoding is delegated to {@see CursorCodec}; this DTO just
 * wraps the opaque string so the read-path signatures can carry it
 * as a typed value rather than a raw `?string`.
 *
 * Use {@see CursorCodec::encode()} / {@see CursorCodec::decode()} for
 * conversion to/from the underlying entry_id; construct this class
 * directly only when you already hold the encoded opaque token (e.g.
 * a consumer round-tripping it through their own storage).
 */
final class Cursor
{
    public function __construct(
        public readonly string $opaque,
    ) {
    }
}
