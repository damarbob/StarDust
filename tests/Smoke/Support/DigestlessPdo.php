<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use PDO;
use PDOStatement;

/**
 * A real connection that reports no value for the server's SHA-256 function.
 *
 * Only a statement that names that function is changed, and it comes back as
 * `SELECT NULL`. Everything else runs unchanged. That is how a server built
 * without the function would answer, which is the case page provisioning has
 * to refuse before it builds a key that is NULL in every row.
 */
final class DigestlessPdo extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (stripos($query, 'SHA2') !== false) {
            return parent::query('SELECT NULL');
        }

        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}
