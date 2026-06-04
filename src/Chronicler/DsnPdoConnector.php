<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use PDO;

/**
 * Default {@see PdoConnector}: builds a fresh PDO from a stored DSN +
 * credentials + driver options.
 *
 * Holding the DSN/credentials (rather than a live PDO) is what makes
 * reconnection possible — `connect()` constructs a brand-new
 * connection each call, so a dead handle can be replaced wholesale.
 * Pass the same options array used for the primary connection so a
 * reconnected handle behaves identically (`ERRMODE_EXCEPTION`,
 * `EMULATE_PREPARES=false`, `DEFAULT_FETCH_MODE=FETCH_ASSOC`).
 */
final class DsnPdoConnector implements PdoConnector
{
    /**
     * @param array<int,mixed> $options PDO driver options.
     */
    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $pass,
        private readonly array $options = [],
    ) {
    }

    public function connect(): PDO
    {
        return new PDO($this->dsn, $this->user, $this->pass, $this->options);
    }
}
