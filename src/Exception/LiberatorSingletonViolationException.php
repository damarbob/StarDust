<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when a second Liberator process attempts to start while
 * another Liberator already holds the PID-file lock.
 *
 * ADR 0009 fixes the Liberator as a strict singleton: a multi-worker
 * sweep delivers no throughput benefit (the workload is IO-bound) and
 * fragments the per-slot `sweep_cursor_id` semantics. The CLI maps
 * this exception to exit code `2` so process supervisors can
 * distinguish "already running" from generic startup failure.
 */
final class LiberatorSingletonViolationException extends RuntimeException
{
}
