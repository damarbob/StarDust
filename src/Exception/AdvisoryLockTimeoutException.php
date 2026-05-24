<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when MySQL `GET_LOCK(name, timeout)` returns `0` (timed out
 * waiting for the lock) or `NULL` (an error occurred while attempting
 * to acquire it). The Watcher catches this in its provision path and
 * emits a `lock_contention` event instead of `provision_failed`.
 */
final class AdvisoryLockTimeoutException extends RuntimeException
{
}
