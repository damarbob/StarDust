<?php

declare(strict_types=1);

namespace StarDust\Read;

use StarDust\Exception\InvalidCursorException;

/**
 * Encode/decode the opaque {@see Cursor} payload.
 *
 * Two wire formats, distinguished by prefix — which is exactly what the
 * `v1:` prefix was introduced for:
 *
 *   - `base64url("v1:" . $entryId)` — the original. Still emitted for an
 *     unsorted read, so tokens issued before sorting existed keep
 *     working and unsorted callers see no change at all.
 *   - `base64url("v2:" . json)` — a sorted read. The JSON carries the
 *     anchor entry id plus the sort key identity and direction.
 *
 * A v2 token records the *ordering* but not the anchor row's sort
 * *value*: the compiler resolves that value from the anchor row at query
 * time. That keeps a token constant-size — embedding the value would
 * make a cursor over a 4096-character string field roughly 22 KB, past
 * every practical URL and header limit — and it keeps ADR 0006's "cursor
 * derived from the id of the last record seen" literally true.
 *
 * The encoding is intentionally trivial — per ADR 0006 the cursor is
 * opaque only by contract, not by cryptographic seal. Consumers MUST
 * NOT inspect or modify the token, but tampering is detected purely
 * by structural validation, not authentication.
 */
final class CursorCodec
{
    private const PREFIX_V1 = 'v1:';
    private const PREFIX_V2 = 'v2:';

    public static function encode(int $entryId): Cursor
    {
        return new Cursor(self::base64UrlEncode(self::PREFIX_V1 . $entryId));
    }

    /**
     * Encodes the next-page token for a read ordered by `$sort`.
     *
     * A `null` sort means the default `entry_data.id ASC`, which emits a
     * v1 token so an unsorted read's tokens are byte-identical to those
     * issued before sorting existed.
     */
    public static function encodeFor(?SortSpec $sort, int $entryId): Cursor
    {
        if ($sort === null) {
            return self::encode($entryId);
        }

        $json = json_encode([
            'k'  => $sort->keyIdentity(),
            'd'  => $sort->direction->value,
            'id' => $entryId,
        ]);
        // json_encode only returns false on unencodable input (resources,
        // NAN, bad UTF-8). Every value here is an int or an engine-owned
        // ASCII string, so this is unreachable — asserted rather than
        // handled so a future field addition cannot fail silently.
        if ($json === false) {
            throw new InvalidCursorException('Cursor encode failed: payload is not encodable.');
        }

        return new Cursor(self::base64UrlEncode(self::PREFIX_V2 . $json));
    }

    /**
     * Decodes a v1 token to its entry id.
     *
     * Retained for callers that only ever deal with the default ordering.
     * A v2 token raises rather than silently dropping its ordering —
     * use {@see decodePayload()} for anything sort-aware.
     */
    public static function decode(Cursor $cursor): int
    {
        return self::decodePayload($cursor)->entryId;
    }

    /**
     * Decodes either wire format into a {@see CursorPayload}.
     */
    public static function decodePayload(Cursor $cursor): CursorPayload
    {
        $decoded = self::base64UrlDecode($cursor->opaque);
        if ($decoded === false) {
            throw new InvalidCursorException(
                'Cursor decode failed: not a valid base64url payload.'
            );
        }

        if (str_starts_with($decoded, self::PREFIX_V1)) {
            return CursorPayload::forDefaultOrder(
                self::parseEntryId(substr($decoded, strlen(self::PREFIX_V1)))
            );
        }

        if (str_starts_with($decoded, self::PREFIX_V2)) {
            return self::decodeV2(substr($decoded, strlen(self::PREFIX_V2)));
        }

        throw new InvalidCursorException('Cursor decode failed: missing version prefix.');
    }

    private static function decodeV2(string $json): CursorPayload
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new InvalidCursorException('Cursor decode failed: malformed v2 payload.');
        }

        $key = $data['k'] ?? null;
        $dir = $data['d'] ?? null;
        $id  = $data['id'] ?? null;

        if (! is_string($key) || $key === '' || ! is_string($dir) || ! is_int($id) || $id < 0) {
            throw new InvalidCursorException('Cursor decode failed: malformed v2 payload.');
        }

        $direction = SortDirection::tryFrom($dir);
        if ($direction === null) {
            throw new InvalidCursorException('Cursor decode failed: unknown sort direction.');
        }

        return CursorPayload::forIdentity($key, $direction, $id);
    }

    private static function parseEntryId(string $idPart): int
    {
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
