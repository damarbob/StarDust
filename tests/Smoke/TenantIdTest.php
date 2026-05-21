<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use PHPUnit\Framework\TestCase;
use StarDust\Exception\InvalidTenantIdException;
use StarDust\Write\TenantId;

/**
 * Pure-PHP validator: this test does not need a database.
 */
final class TenantIdTest extends TestCase
{
    public function testAcceptsOne(): void
    {
        TenantId::assertValid(1);
        self::assertTrue(true);
    }

    public function testAcceptsPhpIntMax(): void
    {
        TenantId::assertValid(PHP_INT_MAX);
        self::assertTrue(true);
    }

    public function testRejectsZero(): void
    {
        $this->expectException(InvalidTenantIdException::class);
        TenantId::assertValid(0);
    }

    public function testRejectsNegative(): void
    {
        $this->expectException(InvalidTenantIdException::class);
        TenantId::assertValid(-1);
    }

    public function testRejectsPhpIntMin(): void
    {
        $this->expectException(InvalidTenantIdException::class);
        TenantId::assertValid(PHP_INT_MIN);
    }
}
