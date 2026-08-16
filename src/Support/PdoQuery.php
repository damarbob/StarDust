<?php

declare(strict_types=1);

namespace StarDust\Support;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Runs a `PDO::query()` and guarantees a `PDOStatement` back.
 *
 * `PDO::query()` is declared to return `PDOStatement|false`, and the
 * `false` really is reachable here even though it looks like it should
 * not be. PHP 8 defaults `PDO::ATTR_ERRMODE` to `ERRMODE_EXCEPTION`, and
 * `bin/stardust` sets it explicitly, so the engine's own processes always
 * get an exception on failure. But the engine takes an **injected** PDO
 * (ADR 0026): a consumer running `ERRMODE_SILENT` — the pre-8.0 default,
 * and still what plenty of framework bootstraps configure — gets `false`
 * back instead, and `->fetchColumn()` on that is a fatal.
 *
 * Chaining off `query()` at nine call sites meant nine places for that to
 * happen. This keeps the failure in one place, where it turns into a
 * typed exception naming the statement that failed.
 *
 * Only for statements with no bound parameters. Anything taking user or
 * row data must still go through `prepare()` + `execute()` — this helper
 * interpolates nothing and must never be handed a built-up string.
 */
final class PdoQuery
{
    /**
     * @throws RuntimeException when the driver reports failure by
     *                          returning `false` rather than throwing.
     */
    public static function run(PDO $pdo, string $sql): PDOStatement
    {
        $stmt = $pdo->query($sql);

        if ($stmt === false) {
            $error = $pdo->errorInfo();
            throw new RuntimeException(sprintf(
                'PDO::query() failed for [%s]: SQLSTATE[%s] %s. If your PDO is '
                . 'configured with ERRMODE_SILENT, switch it to ERRMODE_EXCEPTION '
                . 'so failures surface at the point they occur.',
                $sql,
                $error[0] ?? '?',
                $error[2] ?? 'no driver message',
            ));
        }

        return $stmt;
    }
}
