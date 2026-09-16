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
        /**
         * ADR 0051 leaked disk-probe files. Counted separately and
         * NOT folded into `$artifactsDeleted`: that number is
         * normative in chronicler_daemon.md §6, and a probe file is
         * not an artifact.
         */
        public readonly int $probesDeleted = 0,
    ) {
    }
}
