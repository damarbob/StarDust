<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use PDO;
use PDOStatement;

/**
 * A real connection that remembers the SQL of every statement it prepares, so
 * a test can say how many data-dictionary queries a code path issued.
 */
final class StatementRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $prepared = [];

    /**
     * @param array<mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepared[] = $query;

        return parent::prepare($query, $options);
    }

    /** How many prepared statements contained `$fragment`. */
    public function countPreparedContaining(string $fragment): int
    {
        return count(array_filter($this->prepared, static fn (string $sql): bool => str_contains($sql, $fragment)));
    }
}
