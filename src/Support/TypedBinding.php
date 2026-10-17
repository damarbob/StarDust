<?php

declare(strict_types=1);

namespace StarDust\Support;

use PDO;
use PDOStatement;

/**
 * The one definition of "bind these positional values with the PDO type
 * their PHP type implies".
 *
 * Exists because the engine takes an injected PDO and cannot choose its
 * prepare mode. `PDOStatement::execute(array)` binds every value as
 * `PARAM_STR`, which is harmless under native prepares (the server
 * receives a typed parameter either way), but under
 * `PDO::ATTR_EMULATE_PREPARES => true` (pdo_mysql's own default) the
 * driver inlines each value into the SQL text itself, and a string is
 * inlined **quoted**. A `LIMIT ?` bound that way becomes `LIMIT '51'`,
 * which MySQL and MariaDB both reject with errno 1064. Binding an `int`
 * as `PARAM_INT` makes emulation inline it bare, so the same statement
 * runs under either mode.
 *
 * The mapping is by PHP type only: `int` → `PARAM_INT`, `null` →
 * `PARAM_NULL`, `bool` → `PARAM_BOOL`, and everything else →
 * `PARAM_STR`. **Floats deliberately stay `PARAM_STR`**, because PDO has no
 * float parameter type, and a string is exactly what the untyped
 * `execute(array)` sent before this class existed, so no existing
 * comparison changes meaning.
 *
 * **Shared rather than inlined**, on the `RetryableLockFailure`
 * precedent: it has two callers across two packages today,
 * `Read\PaginatedProbe`, which executes the compiler's fragment, and the
 * smoke suite's direct execution of that same fragment, and a copy that
 * drifts back to an untyped `execute()` fails only under emulation,
 * which the default CI cells do not run.
 */
final class TypedBinding
{
    /**
     * Binds each value to its 1-based positional placeholder.
     *
     * Widened to `array<mixed>` rather than `list<mixed>` on purpose: the
     * keys are re-derived positionally below, so a caller's non-list
     * array still binds in iteration order instead of by its own keys.
     *
     * @param array<mixed> $values
     */
    public static function bind(PDOStatement $stmt, array $values): void
    {
        $position = 1;
        foreach ($values as $value) {
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($position++, $value, $type);
        }
    }
}
