<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use PDO;
use PDOStatement;

/**
 * A real connection that reports a chosen InnoDB page size.
 *
 * A server fixes its page size when its data directory is created, so a test
 * cannot change it. This answers the one query that reads it with
 * `$reportedPageSize` and sends every other statement to the server untouched.
 */
final class PageSizeFakingPdo extends PDO
{
    public int $reportedPageSize = 16384;

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_contains($query, '@@innodb_page_size')) {
            $query = 'SELECT ' . $this->reportedPageSize;
        }

        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}
