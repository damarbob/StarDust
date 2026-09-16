<?php

declare(strict_types=1);

namespace StarDust\Watcher;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * The persisted advisory-sample schedule (ADR 0052).
 *
 * Owns the single `stardust_advisory_schedule` row that paces both the
 * ADR 0019 cardinality advisory and the ADR 0031 spread advisory. The
 * schedule used to be {@see Watcher}'s process-local
 * `$nextAdvisorySampleAt` field, which is correct for a persistent
 * daemon and inert under ADR 0048's combined tick: `StarDust::tick()`
 * builds a fresh object graph per invocation, so a cron-driven host got
 * the always-false first due-check and exited, and the advisories never
 * fired on their own.
 *
 * ## The schedule is fleet-wide, and the claim is what makes it safe
 *
 * Because the state is now in the database rather than in a process,
 * one sample fires per interval across the whole deployment rather than
 * one per daemon. {@see self::claimDue()} is a conditional UPDATE whose
 * affected-row count *is* the claim: racing hosts serialize on the row
 * lock, and the loser re-evaluates the predicate against the winner's
 * committed row, sees a future `next_sample_at`, and matches nothing.
 * Verified on MySQL 8.0.13 with two real OS processes — the second
 * blocked 1979 ms on the row lock and then reported zero.
 *
 * This is a deliberate behaviour change to the persistent-daemon mode:
 * three hosts running `bin/stardust watcher` go from three daily sweeps
 * to one. Both samplers scan the global pool, so the other two were
 * duplicate work.
 *
 * ## Three traps, all measured on 8.0.13
 *
 *   1. **Every datetime is bound from the injected clock**, never
 *      `UTC_TIMESTAMP()`. The session `time_zone` is `SYSTEM` on a
 *      default server, so mixing the two would compare values from two
 *      different clocks — and the frozen-clock scheduling tests drive
 *      this entirely through the injected one.
 *   2. **A reused *named* placeholder is rejected.** `:now` appearing
 *      three times in the claim throws `SQLSTATE[HY093]` under native
 *      prepares, which an injected PDO may well be using. Hence
 *      positional `?` with the value repeated in the bind array — do
 *      not "tidy" these into named parameters.
 *   3. **MySQL reports *changed* rows, not matched rows**, and the
 *      engine cannot set `CLIENT_FOUND_ROWS` on a PDO it does not own.
 *      A claim whose new values equalled the stored ones would report
 *      zero despite matching, and the sample would be skipped in
 *      silence. {@see Watcher::computeNextDue()} floors the next due
 *      time at `$from + 1` so the row always advances.
 *
 * Each mutator seeds the singleton first, with the same
 * `ON DUPLICATE KEY UPDATE id = id` no-op the Bootstrapper uses, so a
 * row deleted out from under a running deployment self-heals on the
 * next poll instead of stalling the advisories for ever.
 */
final class AdvisoryScheduleRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The UTC epoch second the next advisory sample is due, or null when
     * the schedule has never been set (a fresh bootstrap).
     *
     * A plain read, so it takes no lock and may be stale by the time a
     * claim is attempted. That is safe: {@see self::claimDue()}
     * re-evaluates the predicate under the row lock, so this only
     * decides whether a claim is worth attempting at all — which keeps
     * the common not-due tick to a single non-locking SELECT.
     */
    public function read(): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT next_sample_at FROM stardust_advisory_schedule WHERE id = 1'
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false || $row['next_sample_at'] === null) {
            return null;
        }

        return $this->toEpoch((string) $row['next_sample_at']);
    }

    /**
     * Sets the first due time, and only if nothing has set it yet.
     *
     * Returns false for the loser of a first-fire race, which samples
     * nothing either way — the point of the first observation is to
     * phase-randomize, not to sample (ADR 0019).
     */
    public function scheduleFirst(int $phaseEpoch): bool
    {
        $this->seed();

        $stmt = $this->pdo->prepare(
            'UPDATE stardust_advisory_schedule'
            . ' SET next_sample_at = ?, updated_at = ?'
            . ' WHERE id = 1 AND next_sample_at IS NULL'
        );
        $stmt->execute([$this->toSql($phaseEpoch), $this->nowSql()]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Claims the due sample and schedules the next one in one statement.
     *
     * True means this caller won the claim and must run the samplers.
     * The reschedule and the claim cannot be separated: the affected-row
     * count of this single UPDATE is the whole exclusion mechanism.
     */
    public function claimDue(int $nowEpoch, int $nextEpoch): bool
    {
        $this->seed();

        $now = $this->toSql($nowEpoch);

        // Positional, with $now bound three times — see trap 2.
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_advisory_schedule'
            . ' SET next_sample_at = ?, last_sample_at = ?, updated_at = ?'
            . ' WHERE id = 1 AND next_sample_at IS NOT NULL AND next_sample_at <= ?'
        );
        $stmt->execute([$this->toSql($nextEpoch), $now, $now, $now]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Unconditionally records a sample and schedules the next one, for
     * {@see Watcher::sampleAdvisories()} — the `--advisories` force
     * path, which runs the samplers whatever the schedule says.
     *
     * It resets the timer rather than leaving it alone so that a forced
     * sample is not immediately followed by a scheduled one.
     */
    public function force(int $nowEpoch, int $nextEpoch): void
    {
        $this->seed();

        $now = $this->toSql($nowEpoch);

        $stmt = $this->pdo->prepare(
            'UPDATE stardust_advisory_schedule'
            . ' SET next_sample_at = ?, last_sample_at = ?, updated_at = ?'
            . ' WHERE id = 1'
        );
        $stmt->execute([$this->toSql($nextEpoch), $now, $now]);
    }

    private function seed(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_advisory_schedule (id, next_sample_at, last_sample_at, updated_at)'
            . ' VALUES (1, NULL, NULL, ?)'
            . ' ON DUPLICATE KEY UPDATE id = id'
        );
        $stmt->execute([$this->nowSql()]);
    }

    private function nowSql(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function toSql(int $epoch): string
    {
        return (new DateTimeImmutable('@' . $epoch))->format('Y-m-d H:i:s');
    }

    /**
     * The stored value is UTC because everything written here is. The
     * timezone has to be named explicitly — without it PHP applies
     * `date_default_timezone_get()` and the round-trip shifts by the
     * host's offset.
     */
    private function toEpoch(string $datetime): int
    {
        return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))->getTimestamp();
    }
}
