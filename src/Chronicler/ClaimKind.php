<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

/**
 * Distinguishes the two claim paths the Chronicler walks each tick.
 * Carried into the `job_claimed` event payload (chronicler_daemon.md §6).
 */
enum ClaimKind: string
{
    case Pending = 'pending';
    case Abandoned = 'abandoned';
}
