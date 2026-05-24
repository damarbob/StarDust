<?php

declare(strict_types=1);

namespace StarDust\Daemon;

use PDO;
use StarDust\Exception\AdvisoryLockTimeoutException;

/**
 * Wraps MySQL `GET_LOCK(name, timeout)` / `RELEASE_LOCK(name)`.
 *
 * `acquire()` blocks for up to `$timeoutSeconds`; on `0` it throws
 * {@see AdvisoryLockTimeoutException} (lock held by another session)
 * and on `NULL` it throws the same exception with a different message
 * (server-side error). Per blueprint AC#2 the Watcher uses the name
 * `stardust_page_provision` with a 10-second timeout.
 *
 * `release()` is idempotent — calling it twice or after the connection
 * has dropped is safe. The destructor also releases, so a thrown
 * exception inside the protected block cannot leak the lock.
 */
final class AdvisoryLock
{
    private bool $released = false;

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $name,
    ) {
    }

    public static function acquire(PDO $pdo, string $name, int $timeoutSeconds): self
    {
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$name, $timeoutSeconds]);
        $result = $stmt->fetchColumn();

        if ($result === '0' || $result === 0 || $result === false) {
            throw new AdvisoryLockTimeoutException(
                "MySQL GET_LOCK('{$name}', {$timeoutSeconds}) timed out — another session holds the lock."
            );
        }
        if ($result === null) {
            throw new AdvisoryLockTimeoutException(
                "MySQL GET_LOCK('{$name}', {$timeoutSeconds}) returned NULL — server-side error."
            );
        }

        return new self($pdo, $name);
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$this->name]);
        } catch (\Throwable) {
            // Connection dropped or already released — either way the
            // lock will be cleared by MySQL when the session ends.
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
