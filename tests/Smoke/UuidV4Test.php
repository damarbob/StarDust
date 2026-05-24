<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use PHPUnit\Framework\TestCase;
use StarDust\Support\UuidV4;

/**
 * Pure-PHP UUID generator: this test does not need a database.
 *
 * Guards the RFC 4122 v4 shape that ADR 0020 callers and
 * {@see \StarDust\Write\BulkIngestSubmitter} rely on.
 */
final class UuidV4Test extends TestCase
{
    public function testCanonicalShape(): void
    {
        $uuid = UuidV4::generate();
        self::assertSame(36, strlen($uuid));
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    public function testVersionNibbleIsFour(): void
    {
        $uuid = UuidV4::generate();
        self::assertSame('4', $uuid[14]);
    }

    public function testVariantNibbleIsRfc4122(): void
    {
        $uuid = UuidV4::generate();
        self::assertContains($uuid[19], ['8', '9', 'a', 'b']);
    }

    public function testGeneratesDistinctValues(): void
    {
        $values = [];
        for ($i = 0; $i < 64; $i++) {
            $values[UuidV4::generate()] = true;
        }
        self::assertCount(64, $values);
    }
}
