<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Internal Chronicler exception raised by an
 * {@see \StarDust\Chronicler\ArtifactStream} implementation when a
 * single row cannot be encoded into the artifact's chosen format. The
 * surrounding {@see \StarDust\Chronicler\ExportJobProcessor} catches
 * the exception, charges `skip_count`, and emits a `row_skipped` event
 * with the closed-taxonomy `reason`.
 *
 * Allowed reasons (chronicler_daemon.md §6 row_skipped payload):
 *   - `format_invalid`           — CSV/JSON cannot represent the bytes
 *                                   (e.g., embedded NUL in a CSV value).
 *   - `unrepresentable_codepoint` — JSON encoder rejected a lone
 *                                   surrogate or malformed UTF-8.
 */
final class ChroniclerRowEncodingException extends RuntimeException
{
    public const REASON_FORMAT_INVALID = 'format_invalid';
    public const REASON_UNREPRESENTABLE_CODEPOINT = 'unrepresentable_codepoint';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
