<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use PDO;

/**
 * Construction-time seam for re-establishing a database connection.
 *
 * The {@see ExportJobProcessor} uses this to recover from a transient
 * DB disconnect mid-pagination per ADR 0025 Commitment 6: PHP's PDO
 * never auto-reconnects a dead handle, so resuming an in-flight export
 * after a `2006 (server has gone away)` / `2013 (lost connection)`
 * requires building a *fresh* connection. A successful `new PDO(...)`
 * is itself the liveness check (it throws on failure), so an
 * implementation needs no extra ping.
 *
 * Injected via {@see \StarDust\Config\Config::$pdoConnector} (default
 * `null`). When unset the processor cannot reconnect and degrades to
 * the unchanged terminal failure (`failed:query_failure`, `last_cursor`
 * preserved); `bin/stardust chronicler` wires a {@see DsnPdoConnector}
 * built from the same env vars as the primary connection.
 */
interface PdoConnector
{
    public function connect(): PDO;
}
