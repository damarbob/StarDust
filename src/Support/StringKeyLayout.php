<?php

declare(strict_types=1);

namespace StarDust\Support;

use InvalidArgumentException;
use PDO;

/**
 * The one definition of the generated key that indexes a string slot
 * (ADR 0057): its column, its expression and every lookup predicate.
 *
 * A key page gives each string slot `i_str_NN` a companion
 * `i_str_NN_k VARBINARY(48)`, a stored generated column holding the first
 * {@see self::HEAD_BYTES} bytes of the slot's weight string, padded with zero
 * bytes up to that width, followed by the first {@see self::DIGEST_BYTES}
 * bytes of the SHA-256 digest of the whole weight string. The page indexes
 * `(tenant_id, i_str_NN_k)` in place of a prefix of the slot.
 *
 * A lookup that repeats this layout even slightly wrong returns wrong rows
 * and raises nothing, so the page DDL and the compiler both build their SQL
 * here and nowhere else. `StringKeyLayoutTest` fails when `WEIGHT_STRING(` or
 * `SHA2(` appears in any other file under `src/`, so do not write either in a
 * comment elsewhere. Say "weight string" instead.
 *
 * Three traps, each of which returns wrong rows without an error:
 *
 *   - The search value is cast to the page's collation before its weight
 *     string is taken. A bound value arrives in the connection's collation,
 *     and its weight string under that collation matches nothing stored.
 *   - The lower bounds and the equality are padded with zero bytes and the
 *     upper bounds are filled with 0xFF. Without the padding a short value
 *     lands among the longer ones, and `lt`, `lte` and `between` go wrong.
 *   - The value is bound once for each place the SQL names it, as a repeated
 *     positional placeholder. A named placeholder used twice fails under
 *     native prepares.
 *
 * A predicate returned here only has to find a superset of the true matches.
 * The compiler always keeps the original predicate beside it as the recheck.
 *
 * The head width is a parameter of every lookup and never the constant,
 * because a page keeps the head it was built with. Read it from the page's
 * key column width with {@see self::headBytes()}. The constant is only what a
 * page built by this release gets.
 *
 * Every method is a pure function, apart from {@see self::digestAvailable()}.
 */
final class StringKeyLayout
{
    /** Weight string bytes kept, zero padded, at the start of the key on a page built by this release. */
    public const HEAD_BYTES = 32;

    /** SHA-256 digest bytes kept after the head. */
    public const DIGEST_BYTES = 16;

    /** Appended to a slot column's name to name its key column. */
    public const KEY_SUFFIX = '_k';

    private function __construct()
    {
    }

    /** True for `i_str_01` and the other string slot columns of a page, false for any other column. */
    public static function isStringSlot(string $slotColumn): bool
    {
        return preg_match('/\Ai_str_\d{2}\z/', $slotColumn) === 1;
    }

    /** `i_str_01` becomes `i_str_01_k`. */
    public static function keyColumn(string $slotColumn): string
    {
        if (! self::isStringSlot($slotColumn)) {
            throw new InvalidArgumentException("StringKeyLayout: '{$slotColumn}' is not a string slot column.");
        }

        return $slotColumn . self::KEY_SUFFIX;
    }

    /** `i_str_01_k` becomes `i_str_01`, and anything else becomes null. */
    public static function slotColumn(string $keyColumn): ?string
    {
        return preg_match('/\A(i_str_\d{2})' . self::KEY_SUFFIX . '\z/', $keyColumn, $m) === 1
            ? $m[1]
            : null;
    }

    /** The width of a key column that carries `$headBytes` bytes of head. */
    public static function keyWidth(int $headBytes = self::HEAD_BYTES): int
    {
        self::assertHead($headBytes);

        return $headBytes + self::DIGEST_BYTES;
    }

    /** The head width of a page whose key column is `$keyWidth` bytes wide. */
    public static function headBytes(int $keyWidth): int
    {
        $head = $keyWidth - self::DIGEST_BYTES;
        self::assertHead($head);

        return $head;
    }

    /**
     * The generated column definition for `$slotColumn`, as it appears in the
     * page's `CREATE TABLE`. The expression reads the slot's own collation,
     * which is the table's.
     */
    public static function columnDdl(string $slotColumn): string
    {
        $key = self::keyColumn($slotColumn);

        return sprintf(
            '%s VARBINARY(%d) GENERATED ALWAYS AS (%s) STORED',
            $key,
            self::keyWidth(),
            self::keyExpression(self::HEAD_BYTES, "WEIGHT_STRING({$slotColumn})"),
        );
    }

    /**
     * Whether the server can compute the digest. A server whose SHA-2 function
     * returns NULL would give every row a NULL key and every equality zero
     * rows, with no error, so page provisioning checks this first.
     */
    public static function digestAvailable(PDO $pdo): bool
    {
        $digest = PdoQuery::run($pdo, "SELECT SHA2('x', 256)")->fetchColumn();

        return is_string($digest) && strlen($digest) === 64;
    }

    /**
     * Whether a collation name is safe to put into SQL. The name comes from the
     * data dictionary, and a name that fails this is ignored, never quoted.
     */
    public static function isSafeCollation(string $collation): bool
    {
        return preg_match('/\A[A-Za-z0-9_]+\z/', $collation) === 1;
    }

    /**
     * `k = <key of $operand>`, for `eq`.
     *
     * @return array{sql: string, bindings: list<string>}
     */
    public static function equals(string $keyColumn, string $operand, int $headBytes, string $collation): array
    {
        $sql = $keyColumn . ' = ' . self::keyExpression($headBytes, self::searchWeight($collation));

        return ['sql' => $sql, 'bindings' => self::operands($sql, $operand)];
    }

    /**
     * `k IN (<key of each operand>)`, for `in`. At least one operand.
     *
     * @param  list<string> $operands
     * @return array{sql: string, bindings: list<string>}
     */
    public static function inList(string $keyColumn, array $operands, int $headBytes, string $collation): array
    {
        if ($operands === []) {
            throw new InvalidArgumentException('StringKeyLayout: an IN list needs at least one operand.');
        }

        $expression = self::keyExpression($headBytes, self::searchWeight($collation));

        $bindings = [];
        foreach ($operands as $operand) {
            array_push($bindings, ...self::operands($expression, $operand));
        }

        return [
            'sql'      => $keyColumn . ' IN (' . implode(', ', array_fill(0, count($operands), $expression)) . ')',
            'bindings' => $bindings,
        ];
    }

    /**
     * The keys that can belong to a value starting with `$prefix`, for `prefix`.
     * `$prefix` is the text before the wildcard, unescaped.
     *
     * Both bounds come from the unpadded head. A padded lower bound would sit
     * above the key of a value that continues with a low byte, and the upper
     * bound is filled to the full key width so it clears any key that starts
     * with the head.
     *
     * @return array{sql: string, bindings: list<string>}
     */
    public static function prefix(string $keyColumn, string $prefix, int $headBytes, string $collation): array
    {
        $head = 'LEFT(' . self::searchWeight($collation) . ', ' . self::assertHead($headBytes) . ')';
        $sql = sprintf(
            "%s BETWEEN %s AND CONCAT(%s, REPEAT(UNHEX('FF'), %d))",
            $keyColumn,
            $head,
            $head,
            self::keyWidth($headBytes),
        );

        return ['sql' => $sql, 'bindings' => self::operands($sql, $prefix)];
    }

    /**
     * The keys of values at or above `$operand`, for `gt` and `gte`. The strict
     * form finds the same superset and the recheck drops the equal ones.
     *
     * @return array{sql: string, bindings: list<string>}
     */
    public static function atLeast(string $keyColumn, string $operand, int $headBytes, string $collation): array
    {
        $sql = $keyColumn . ' >= ' . self::paddedHead($headBytes, self::searchWeight($collation));

        return ['sql' => $sql, 'bindings' => self::operands($sql, $operand)];
    }

    /**
     * The keys of values at or below `$operand`, for `lt` and `lte`. Every key
     * that shares the operand's head is included, whatever its digest.
     *
     * @return array{sql: string, bindings: list<string>}
     */
    public static function atMost(string $keyColumn, string $operand, int $headBytes, string $collation): array
    {
        $sql = sprintf(
            "%s <= CONCAT(%s, REPEAT(UNHEX('FF'), %d))",
            $keyColumn,
            self::paddedHead($headBytes, self::searchWeight($collation)),
            self::DIGEST_BYTES,
        );

        return ['sql' => $sql, 'bindings' => self::operands($sql, $operand)];
    }

    /**
     * Both bounds, for `between`.
     *
     * @return array{sql: string, bindings: list<string>}
     */
    public static function between(
        string $keyColumn,
        string $lower,
        string $upper,
        int $headBytes,
        string $collation,
    ): array {
        $low = self::atLeast($keyColumn, $lower, $headBytes, $collation);
        $high = self::atMost($keyColumn, $upper, $headBytes, $collation);

        return [
            'sql'      => $low['sql'] . ' AND ' . $high['sql'],
            'bindings' => [...$low['bindings'], ...$high['bindings']],
        ];
    }

    /**
     * The full key, from a weight string expression: the padded head, then the
     * digest. The generated column and the equality lookup both use it, so the
     * stored key and the searched key cannot disagree.
     */
    private static function keyExpression(int $headBytes, string $weight): string
    {
        return sprintf(
            'CONCAT(%s, LEFT(UNHEX(SHA2(%s, 256)), %d))',
            self::paddedHead($headBytes, $weight),
            $weight,
            self::DIGEST_BYTES,
        );
    }

    private static function paddedHead(int $headBytes, string $weight): string
    {
        $head = self::assertHead($headBytes);

        return sprintf("RPAD(LEFT(%s, %d), %d, UNHEX('00'))", $weight, $head, $head);
    }

    /**
     * The weight string of a bound value under the page's collation. The cast
     * to that collation is what makes it match what is stored.
     */
    private static function searchWeight(string $collation): string
    {
        if (! self::isSafeCollation($collation)) {
            throw new InvalidArgumentException('StringKeyLayout: the collation name is not a plain identifier.');
        }

        return "WEIGHT_STRING(CONVERT(? USING utf8mb4) COLLATE {$collation})";
    }

    /**
     * One binding of `$operand` for every placeholder in `$sql`, in order.
     * Counting the placeholders keeps the bindings from drifting away from the
     * SQL, and nothing else in the SQL can be a question mark because the
     * collation is checked first.
     *
     * @return list<string>
     */
    private static function operands(string $sql, string $operand): array
    {
        return array_fill(0, substr_count($sql, '?'), $operand);
    }

    private static function assertHead(int $headBytes): int
    {
        if ($headBytes < 1) {
            throw new InvalidArgumentException("StringKeyLayout: a head of {$headBytes} bytes is not valid.");
        }

        return $headBytes;
    }
}
