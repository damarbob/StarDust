<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Search;

use Psr\Log\NullLogger;
use StarDust\Filter\Limits\FilterLimits;
use StarDust\Read\SchemaVersionCache;
use StarDust\Search\Mysql\MysqlNativeDriver;
use StarDust\Support\ServerEngine;
use StarDust\Tests\Smoke\Phase8TestCase;

/**
 * Pins the `max_sort_length` session tuning {@see MysqlNativeDriver}'s
 * constructor applies on a MariaDB target (ADR 0041's 2026-09-20
 * correction).
 *
 * MariaDB genuinely truncates `ORDER BY` comparison on `TEXT` columns
 * at `max_sort_length` bytes — verified directly in SQL against real
 * 10.6/10.11/11 servers, collation-independent. MySQL 8.0.13 does not
 * have this problem at all, which is the premise `src/Read/CLAUDE.md`'s
 * "string slots sort exactly, on the full value" claim rests on.
 * {@see \StarDust\Tests\Smoke\SortCursorPaginationTest::testWalksStringsThatDivergeBeyondTheSortLengthLimit}
 * is the behavioural proof this fixes; this test pins the mechanism
 * that fixes it, so a regression here is legible on its own rather
 * than only as a pagination symptom.
 */
final class MysqlNativeDriverServerTuningTest extends Phase8TestCase
{
    public function testMariaDbGetsMaxSortLengthRaisedToTheFullStringSlotBound(): void
    {
        if ($this->engine !== ServerEngine::MARIADB) {
            self::markTestSkipped('This assertion is MariaDB-specific; see the MySQL counterpart below.');
        }

        new MysqlNativeDriver(
            pdo:    $this->pdo,
            logger: new NullLogger(),
            cache:  new SchemaVersionCache($this->pdo, new NullLogger()),
        );

        $value = (int) $this->pdo->query('SELECT @@max_sort_length')->fetchColumn();
        self::assertSame(FilterLimits::DEFAULT_MAX_STRING_LENGTH * 4, $value);
    }

    public function testMySqlIsLeftAtWhateverTheServerDefaultIs(): void
    {
        if ($this->engine !== ServerEngine::MYSQL) {
            self::markTestSkipped('This assertion is MySQL-specific; see the MariaDB counterpart above.');
        }

        $before = (int) $this->pdo->query('SELECT @@max_sort_length')->fetchColumn();

        new MysqlNativeDriver(
            pdo:    $this->pdo,
            logger: new NullLogger(),
            cache:  new SchemaVersionCache($this->pdo, new NullLogger()),
        );

        $after = (int) $this->pdo->query('SELECT @@max_sort_length')->fetchColumn();
        self::assertSame($before, $after, 'MySQL correctness never depended on this setting; the driver must not touch it.');
    }
}
