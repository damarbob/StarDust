<?php

declare(strict_types=1);

namespace StarDust\Support;

/**
 * RFC 4122 v4 UUID generator built from 16 cryptographically random
 * bytes. The version nibble (byte 6, high) is forced to `4` and the
 * variant nibble (byte 8, high) to the 10xx pattern (`8|9|a|b`).
 *
 * Two roles in the engine today:
 *   - `correlation_id` synthesis for ADR 0020 NDJSON events
 *     ({@see \StarDust\Logging\StdoutNdjsonLogger},
 *     {@see \StarDust\Read\EntryReader}).
 *   - Artifact filename uniquifier for async bulk submission
 *     ({@see \StarDust\Write\BulkIngestSubmitter}).
 */
final class UuidV4
{
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
