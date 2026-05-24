<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when a second Watcher process attempts to start while another
 * Watcher is already holding the PID-file lock.
 *
 * ADR 0008 mandates the Watcher run as a strict singleton; ADR 0027
 * pins PID-file enforcement as the primary mechanism (the in-DB
 * `GET_LOCK` advisory is the safety net). The CLI maps this exception
 * to exit code `2` so process supervisors can distinguish "already
 * running" from generic startup failure.
 */
final class WatcherSingletonViolationException extends RuntimeException
{
}
