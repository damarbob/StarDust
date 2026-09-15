<?php

declare(strict_types=1);

namespace StarDust\Daemon;

/**
 * Resolves how many seconds {@see CombinedTick::run()} is allowed to
 * keep looping, per ADR 0048.
 *
 * Two ceilings compete: the operator's configured
 * `Config::$tickBudgetSeconds`, and the SAPI's `max_execution_time`
 * (nonzero only on a web-facing SAPI — the CLI SAPI's default is
 * already `0`, verified empirically rather than assumed). The lower
 * one wins, less a safety margin, so a cron- or URL-driven run cannot
 * itself get killed mid-round by the timeout it is trying to respect.
 */
final class TickBudget
{
    private function __construct(
        public readonly int $seconds,
        /** Whether `max_execution_time` forced the budget below the configured value. */
        public readonly bool $clamped,
    ) {
    }

    public static function resolve(int $configuredSeconds, int $marginSeconds): self
    {
        // Best-effort: succeeds silently under the CLI SAPI (verified —
        // ini_get('max_execution_time') already reads '0' there, so
        // this is a no-op in the deployment mode this budget exists
        // for) and fails silently under a SAPI that forbids it (some
        // FPM pools); either way execution continues and the clamp
        // below still applies if a ceiling remains.
        //
        // Note this is also why the clamp branch cannot be exercised by
        // ini_set()-ing max_execution_time from inside a test process:
        // a successful set_time_limit(0) call resets the ini value to
        // 0 itself, so real SAPI state can only ever demonstrate the
        // unclamped path. fromCeiling() below exists so the clamp
        // arithmetic is testable independent of that.
        @set_time_limit(0);

        return self::fromCeiling($configuredSeconds, $marginSeconds, (int) ini_get('max_execution_time'));
    }

    /**
     * The clamp arithmetic on its own, given an already-read
     * `max_execution_time`. Separated from {@see resolve()} so
     * `TickBudgetTest` can drive the full clamp matrix without
     * depending on real, mutable PHP SAPI state — see the note above.
     */
    public static function fromCeiling(int $configuredSeconds, int $marginSeconds, int $maxExecutionTime): self
    {
        if ($maxExecutionTime <= 0) {
            // No PHP-level ceiling — either the SAPI never had one
            // (CLI) or set_time_limit(0) just lifted it. The configured
            // budget governs outright.
            return new self($configuredSeconds, false);
        }

        $ceiling = $maxExecutionTime - $marginSeconds;
        if ($ceiling >= $configuredSeconds) {
            return new self($configuredSeconds, false);
        }

        // Clamped below the configured value. A ceiling at or under
        // zero is not an error — it means "run exactly one round, then
        // stop", which CombinedTick's loop honours with no special
        // case: the budget is only checked after a round completes.
        return new self(max($ceiling, 0), true);
    }
}
