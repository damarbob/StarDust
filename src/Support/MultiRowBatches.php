<?php

declare(strict_types=1);

namespace StarDust\Support;

/**
 * Cuts the rows of one chunk into the sub-batches a multi-row statement can
 * carry. The chunk writer and the retype backfill both send many rows per
 * INSERT, and both must stay under two limits: the prepared-statement
 * placeholder cap and the server's packet size. This is the one definition
 * of how that cut is made.
 *
 * Sizes are estimates, not measurements. A string counts twice its length
 * because an emulated prepare sends it escaped inline and escaping at most
 * doubles a byte, plus its quotes and separator.
 */
final class MultiRowBatches
{
    /** Headroom below MySQL's 65 535 placeholders per prepared statement. */
    public const DEFAULT_MAX_PLACEHOLDERS = 60000;

    /** Fixed allowance for the SQL text around the VALUES list. */
    private const STATEMENT_OVERHEAD_BYTES = 1024;

    /**
     * Upper-bound wire size of one VALUES tuple.
     *
     * @param list<mixed> $values
     */
    public static function costOf(array $values): int
    {
        $bytes = 4;
        foreach ($values as $v) {
            $bytes += is_string($v) ? 2 * strlen($v) + 3 : 24;
        }
        return $bytes;
    }

    /**
     * Consecutive `[offset, length]` ranges that each fit both budgets.
     * Order is preserved, which the chunk writer's id derivation depends
     * on, and a row that alone exceeds the byte budget still gets a range
     * of its own.
     *
     * `$maxStatementBytes` is a callable so the caller's session read
     * happens only when there is more than one row to cut. A single row
     * never needs the packet size.
     *
     * @param list<int>      $costs               estimated bytes per row
     * @param callable():int $maxStatementBytes   byte budget per statement
     * @return list<array{int, int}>
     */
    public static function ranges(
        array $costs,
        int $placeholdersPerRow,
        int $maxPlaceholders,
        callable $maxStatementBytes,
    ): array {
        $count = count($costs);
        if ($count <= 1) {
            return $count === 0 ? [] : [[0, 1]];
        }

        $maxRows = max(1, intdiv($maxPlaceholders, $placeholdersPerRow));
        $maxBytes = max(1, $maxStatementBytes() - self::STATEMENT_OVERHEAD_BYTES);

        $ranges = [];
        $start = 0;
        $bytes = 0;
        for ($i = 0; $i < $count; $i++) {
            $rows = $i - $start;
            if ($rows > 0 && ($rows >= $maxRows || $bytes + $costs[$i] > $maxBytes)) {
                $ranges[] = [$start, $rows];
                $start = $i;
                $bytes = 0;
            }
            $bytes += $costs[$i];
        }
        $ranges[] = [$start, $count - $start];

        return $ranges;
    }
}
