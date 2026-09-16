<?php

declare(strict_types=1);

namespace StarDust\Daemon;

/**
 * Cooperative-yield probe for a long-running unit of work that can
 * only hand control back at its own chunk boundaries (ADR 0050).
 *
 * Unlike {@see ShutdownSignal} — a plain bool — this reports a
 * closed-taxonomy *cause* (`'budget'` | `'shutdown'`, or `null` for
 * "keep going") so the caller's terminal event can say *why* it
 * stopped without a second probe. `null` means "no yield requested";
 * any non-null string is a request to yield at the next opportunity.
 *
 * The only production consumer today is
 * {@see \StarDust\Chronicler\ExportJobProcessor::process()}, which
 * checks this once per committed chunk — never before the first
 * commit, so a yield always carries at least one chunk of durable
 * progress.
 */
interface YieldSignal
{
    /** @return string|null A closed-taxonomy cause, or `null` to keep going. */
    public function yieldCause(): ?string;
}
