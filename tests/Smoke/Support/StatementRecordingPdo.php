<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use PDO;
use PDOStatement;

/**
 * A real connection that remembers the SQL it was asked to run, so a test can
 * say how many statements a code path issued.
 *
 * `$prepared` holds only what went through `prepare()`, which is enough to count
 * data-dictionary queries. `$statements` holds everything in the order it was
 * sent, whichever of `prepare()`, `query()` or `exec()` carried it. The
 * schema-version probe uses `PDO::query()`, so counting a whole read needs the
 * second list. A prepared statement counts once however many times it runs.
 */
final class StatementRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $prepared = [];

    /** @var list<string> */
    public array $statements = [];

    /**
     * @param array<mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepared[] = $query;
        $this->statements[] = $query;

        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->statements[] = $query;

        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;

        return parent::exec($statement);
    }

    /** How many prepared statements contained `$fragment`. */
    public function countPreparedContaining(string $fragment): int
    {
        return count(array_filter($this->prepared, static fn (string $sql): bool => str_contains($sql, $fragment)));
    }
}
