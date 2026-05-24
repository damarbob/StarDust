<?php

declare(strict_types=1);

namespace StarDust\Read;

use StarDust\Exception\InvalidCursorException;

/**
 * Encode/decode the opaque {@see Cursor} payload.
 *
 * Wire format: base64url(`"v1:" . $entryId`). The `v1:` prefix lets
 * future cursor revisions roll forward without ambiguity; an opaque
 * token without it is rejected as malformed.
 *
 * The encoding is intentionally trivial — per ADR 0006 the cursor is
 * opaque only by contract, not by cryptographic seal. Consumers MUST
 * NOT inspect or modify the token, but tampering is detected purely
 * by structural validation, not authentication.
 */
final class CursorCodec
{
    private const PREFIX = 'v1:';

    public static function encode(int $entryId): Cursor
    {
        $b64 = self::base64UrlEncode(self::PREFIX . $entryId);
        return new Cursor($b64);
    }

    public static function decode(Cursor $cursor): int
    {
        $decoded = self::base64UrlDecode($cursor->opaque);
        if ($decoded === false) {
            throw new InvalidCursorException(
                'Cursor decode failed: not a valid base64url payload.'
            );
        }

        if (! str_starts_with($decoded, self::PREFIX)) {
            throw new InvalidCursorException(
                'Cursor decode failed: missing version prefix.'
            );
        }

        $idPart = substr($decoded, strlen(self::PREFIX));
        // Disallow leading zeros, signs, and non-digits — the encode
        // path only produces canonical integer strings.
        if ($idPart === '' || ! ctype_digit($idPart)) {
            throw new InvalidCursorException(
                'Cursor decode failed: malformed entry id payload.'
            );
        }

        return (int) $idPart;
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $opaque): string|false
    {
        $pad = strlen($opaque) % 4;
        if ($pad > 0) {
            $opaque .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($opaque, '-_', '+/'), true);
    }
}
