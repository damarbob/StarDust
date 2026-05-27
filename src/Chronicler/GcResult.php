<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

/**
 * Outcome of one {@see GcSweeper::sweep()} cycle. Used to suppress the
 * `gc_swept` event on no-op cycles (chronicler_daemon.md §4: idle ticks
 * emit nothing).
 */
final class GcResult
{
    public function __construct(
        public readonly int $artifactsDeleted,
        public readonly int $bytesReclaimed,
    ) {
    }
}
