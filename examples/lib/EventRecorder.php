<?php

declare(strict_types=1);

namespace StarDust\Examples;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that keeps StarDust's events instead of printing them.
 *
 * The engine's default logger is {@see \StarDust\Logging\StdoutNdjsonLogger},
 * which writes a JSON line per event to stdout. That is the right default
 * for a daemon and exactly wrong for a repainting frame, so the examples
 * inject this instead: every event is captured, and the example decides
 * which ones are worth showing a beginner.
 *
 * Injecting it is also the answer to "how do I get StarDust events into
 * my own logs?" — `new Config(pdo: $pdo, logger: $yourPsr3Logger)`.
 */
final class EventRecorder extends AbstractLogger
{
    /** @var list<array{at: float, event: string, context: array<string, mixed>}> */
    private array $events = [];

    private int $consumed = 0;

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        // Every StarDust event carries an `event` key from the ADR 0020
        // closed vocabulary. Anything without one is not ours.
        $name = $context['event'] ?? null;
        if (! is_string($name)) {
            return;
        }

        /** @var array<string, mixed> $context */
        $this->events[] = ['at' => microtime(true), 'event' => $name, 'context' => $context];
    }

    /**
     * Events recorded since the last call — the frame loop drains this
     * each tick so nothing is rendered twice.
     *
     * @return list<array{at: float, event: string, context: array<string, mixed>}>
     */
    public function drain(): array
    {
        $fresh = \array_slice($this->events, $this->consumed);
        $this->consumed = \count($this->events);

        return array_values($fresh);
    }

    /** Total events seen, including already-drained ones. */
    public function count(): int
    {
        return \count($this->events);
    }
}
