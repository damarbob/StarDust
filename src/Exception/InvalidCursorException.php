<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Phase 4 cursor decoding failed: the opaque cursor string supplied to
 * `read()` is malformed, tampered with, or from an incompatible
 * cursor-format version.
 *
 * Per ADR 0006 the cursor is opaque to consumers — they may not
 * inspect or modify it, only pass it back. Any deviation from that
 * contract surfaces here.
 */
final class InvalidCursorException extends RuntimeException
{
}
