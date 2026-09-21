<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StarDust\Support\ServerEngine;
use StarDust\Support\ServerEngineDetector;

/**
 * Boundary coverage for {@see ServerEngineDetector}, ADR 0055's fail-
 * closed engine detection.
 *
 * `testDetectsTheConnectedServerWithoutThrowing()` is the one test
 * here that needs a real connection — it proves `detect()` accepts
 * whatever server the smoke suite is actually pointed at, the same
 * call `EnvironmentTest::testServerIsASupportedEngine()` and
 * `StarDust::serverEngine()` make.
 *
 * Everything else exercises the private parsing/comparison methods
 * directly via reflection, DB-free, in the same spirit as
 * {@see \StarDust\Tests\Smoke\DialectTest} — these are the exact
 * boundary cases verified ad hoc against real MySQL 8.0.13 and real
 * MariaDB 10.6/10.11/11 containers during the Stage 2 implementation
 * (2026-09-20); this file is what makes that verification permanent
 * rather than a discarded scratch script.
 */
final class ServerEngineDetectorTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn  = getenv('STARDUST_TEST_DSN') ?: '';
        $user = getenv('STARDUST_TEST_USER') ?: '';
        $pass = getenv('STARDUST_TEST_PASS') ?: '';

        if ($dsn === '' || $user === '') {
            self::markTestSkipped('STARDUST_TEST_DSN and STARDUST_TEST_USER must be set for smoke tests.');
        }

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            self::fail('Could not connect to test database: ' . $e->getMessage());
        }
    }

    public function testDetectsTheConnectedServerWithoutThrowing(): void
    {
        $engine = ServerEngineDetector::detect($this->pdo);
        self::assertContains($engine, [ServerEngine::MYSQL, ServerEngine::MARIADB]);
    }

    /** @return array<string, array{string, bool}> [rawVersion, expectBelowMysqlFloor] */
    public static function mysqlFloorCases(): array
    {
        return [
            'just below floor' => ['8.0.12', true],
            'exactly at floor'  => ['8.0.13', false],
            'above floor'       => ['8.0.14', false],
            'MySQL 5.7 well below floor' => ['5.7.44', true],
        ];
    }

    /** @dataProvider mysqlFloorCases */
    public function testMysqlFloorBoundary(string $raw, bool $expectBelow): void
    {
        self::assertSame($expectBelow, $this->isBelowMysqlFloor($raw));
    }

    /** @return array<string, array{string, bool}> [rawVersion, expectBelowMariaDbFloor] */
    public static function mariaDbFloorCases(): array
    {
        return [
            'just below floor' => ['10.10.9-MariaDB', true],
            'exactly at floor'  => ['10.11.0-MariaDB', false],
            'well above floor'  => ['11.8.9-MariaDB-ubu2404', false],
        ];
    }

    /** @dataProvider mariaDbFloorCases */
    public function testMariaDbFloorBoundary(string $raw, bool $expectBelow): void
    {
        self::assertSame($expectBelow, $this->isBelowMariaDbFloor($raw));
    }

    /**
     * MariaDB's historical `5.5.5-` old-client-compat prefix must be
     * stripped before the version is parsed, or `10.11.19-MariaDB`
     * reads as `5.5.5` — fails closed, but as "MySQL below floor",
     * sending an operator hunting a problem they do not have (ADR 0055
     * §5). Not observed under this project's own mysqlnd-based CI
     * driver (Stage 1 probe, 2026-09-20), so this is defensive rather
     * than reproducing a measured failure.
     */
    public function testLegacyPrefixIsStrippedBeforeParsing(): void
    {
        $ref = new ReflectionClass(ServerEngineDetector::class);

        $stripLegacyPrefix = $ref->getMethod('stripLegacyPrefix');
        $stripLegacyPrefix->setAccessible(true);
        $parseLeadingVersion = $ref->getMethod('parseLeadingVersion');
        $parseLeadingVersion->setAccessible(true);
        $containsMarker = $ref->getMethod('containsMariaDbMarker');
        $containsMarker->setAccessible(true);

        $raw = '5.5.5-10.11.19-MariaDB';

        self::assertTrue($containsMarker->invoke(null, $raw));

        $stripped = $stripLegacyPrefix->invoke(null, $raw);
        self::assertSame('10.11.19-MariaDB', $stripped);

        $parsed = $parseLeadingVersion->invoke(null, $stripped);
        self::assertSame([10, 11, 19], $parsed);
    }

    public function testThrowsOnAnUnparseableVersionString(): void
    {
        $ref = new ReflectionClass(ServerEngineDetector::class);
        $parseLeadingVersion = $ref->getMethod('parseLeadingVersion');
        $parseLeadingVersion->setAccessible(true);

        self::assertNull($parseLeadingVersion->invoke(null, 'not-a-version-string'));
    }

    private function isBelowMysqlFloor(string $raw): bool
    {
        return $this->isBelowFloorFor($raw, [8, 0, 13]);
    }

    private function isBelowMariaDbFloor(string $raw): bool
    {
        $ref = new ReflectionClass(ServerEngineDetector::class);
        $stripLegacyPrefix = $ref->getMethod('stripLegacyPrefix');
        $stripLegacyPrefix->setAccessible(true);

        return $this->isBelowFloorFor((string) $stripLegacyPrefix->invoke(null, $raw), [10, 11, 0]);
    }

    /** @param array{int, int, int} $floor */
    private function isBelowFloorFor(string $raw, array $floor): bool
    {
        $ref = new ReflectionClass(ServerEngineDetector::class);
        $parseLeadingVersion = $ref->getMethod('parseLeadingVersion');
        $parseLeadingVersion->setAccessible(true);
        $isBelowFloor = $ref->getMethod('isBelowFloor');
        $isBelowFloor->setAccessible(true);

        $parsed = $parseLeadingVersion->invoke(null, $raw);
        self::assertNotNull($parsed, "Failed to parse version from '{$raw}'.");

        return $isBelowFloor->invoke(null, $parsed, $floor);
    }
}
