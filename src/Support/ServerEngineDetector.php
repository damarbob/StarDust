<?php

declare(strict_types=1);

namespace StarDust\Support;

use PDO;
use StarDust\Exception\UnsupportedServerException;

/**
 * Detects {@see ServerEngine} from a live connection (ADR 0055).
 *
 * There is no `Config` input here by design (ADR 0055 §7) — the server
 * is the only authority, and detection fails closed rather than
 * defaulting to MySQL on anything it does not recognise.
 *
 * ## Version source (ADR 0055 §6)
 *
 * `PDO::ATTR_SERVER_VERSION` is read first — it arrives on the
 * connection handshake at no extra round trip. Measured against real
 * MariaDB 10.6/10.11/11 containers under PHP 8.4's mysqlnd driver
 * (2026-09-20): none carried the historical `5.5.5-` old-client-compat
 * prefix. That is the driver this project's CI also uses
 * (`shivammathur/setup-php` defaults to mysqlnd on its Ubuntu runners),
 * so it is expected to hold across the whole PHP matrix — but it was
 * not measured against libmysqlclient, where the prefix is documented
 * MariaDB behaviour. `stripLegacyPrefix()` handles it defensively
 * either way. A blank or unreadable attribute falls back to one
 * `SELECT VERSION()` round trip.
 *
 * ## Engine match (ADR 0055 §5)
 *
 * The `MariaDB` marker is matched **before** any numeric parsing, never
 * a version-number range — a naive leading-triple parse of the
 * `5.5.5-10.11.19-MariaDB` shape reads `5.5.5`, which would fail closed
 * but as "MySQL below floor", sending an operator hunting a problem
 * they do not have.
 */
final class ServerEngineDetector
{
    /** ADR 0023 / ADR 0054 floors, as `[major, minor, patch]`. */
    private const MYSQL_FLOOR   = [8, 0, 13];
    private const MARIADB_FLOOR = [10, 11, 0];

    public static function detect(PDO $pdo): ServerEngine
    {
        $raw = self::readVersionString($pdo);

        if (self::containsMariaDbMarker($raw)) {
            $version = self::parseLeadingVersion(self::stripLegacyPrefix($raw));
            if ($version === null || self::isBelowFloor($version, self::MARIADB_FLOOR)) {
                throw new UnsupportedServerException(
                    "Unsupported MariaDB version '{$raw}': StarDust requires MariaDB 10.11.0 or later."
                );
            }
            return ServerEngine::MARIADB;
        }

        $version = self::parseLeadingVersion($raw);
        if ($version === null || self::isBelowFloor($version, self::MYSQL_FLOOR)) {
            throw new UnsupportedServerException(
                "Unsupported server version '{$raw}': StarDust requires MySQL/Percona 8.0.13"
                . ' or later, or MariaDB 10.11.0 or later.'
            );
        }
        return ServerEngine::MYSQL;
    }

    private static function readVersionString(PDO $pdo): string
    {
        $attr = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        if (is_string($attr) && $attr !== '') {
            return $attr;
        }

        $fallback = PdoQuery::run($pdo, 'SELECT VERSION()')->fetchColumn();
        return is_string($fallback) ? $fallback : '';
    }

    private static function containsMariaDbMarker(string $raw): bool
    {
        return stripos($raw, 'MariaDB') !== false;
    }

    /**
     * MariaDB has historically prefixed its reported version with
     * `5.5.5-` for old-client compatibility. Stripped defensively even
     * though it was not observed under mysqlnd (see class docblock) —
     * cheap to handle, expensive to discover missing in production.
     */
    private static function stripLegacyPrefix(string $raw): string
    {
        return str_starts_with($raw, '5.5.5-') ? substr($raw, strlen('5.5.5-')) : $raw;
    }

    /** @return array{int, int, int}|null */
    private static function parseLeadingVersion(string $raw): ?array
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $raw, $m) !== 1) {
            return null;
        }
        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    /**
     * @param array{int, int, int} $version
     * @param array{int, int, int} $floor
     */
    private static function isBelowFloor(array $version, array $floor): bool
    {
        return version_compare(implode('.', $version), implode('.', $floor), '<');
    }
}
