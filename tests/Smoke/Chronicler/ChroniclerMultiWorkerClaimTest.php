<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use PDO;
use StarDust\Chronicler\ExportJobClaimer;
use StarDust\Clock\SystemClock;
use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * Two-session SKIP LOCKED proof:
 *
 *   Worker A holds a sibling-session `FOR UPDATE` on row 1 (no
 *   SKIP LOCKED — a plain blocking lock). Worker B's claimer issues
 *   the production `SELECT … FOR UPDATE SKIP LOCKED LIMIT 1` and
 *   MUST skip row 1, returning row 2 instead. Row 1 stays `pending`
 *   under A's lock; A then commits to release.
 *
 *   This proves the SKIP LOCKED predicate is actually honored at
 *   the SQL level — two concurrent claimers never block on the same
 *   row, they each take the next available one.
 */
final class ChroniclerMultiWorkerClaimTest extends Phase7TestCase
{
    public function testSkipLockedRoutesClaimerAroundHeldRow(): void
    {
        // Build a second PDO session against the same database so we
        // can hold row 1 locked from session A while session B's
        // claimer runs.
        $sibling = $this->makeSiblingPdo();

        $modelId = $this->createModel(1, 'multiworker');
        $this->createFieldNamed($modelId, 'k');

        // Seed two pending jobs for the same tenant. Their created_at
        // ordering means the per-tenant round-robin claim would pick
        // the older one (row 1) first.
        $row1 = $this->seedExportJob(1, $modelId, 'pending', 'csv',
            createdAt: '2026-05-27 10:00:00');
        $row2 = $this->seedExportJob(1, $modelId, 'pending', 'csv',
            createdAt: '2026-05-27 10:00:01');

        try {
            // Session A: BEGIN + FOR UPDATE on row 1. The lock is
            // held until we COMMIT.
            $sibling->beginTransaction();
            $stmt = $sibling->prepare(
                'SELECT id FROM stardust_export_jobs WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$row1]);
            self::assertSame($row1, (int) $stmt->fetchColumn());

            // Session B (production PDO via the claimer): SELECT … FOR
            // UPDATE SKIP LOCKED LIMIT 1 should skip row 1 (held by A)
            // and return row 2.
            $claimer = new ExportJobClaimer(
                pdo: $this->pdo,
                clock: new SystemClock(),
                leaseTimeoutSeconds: 30,
            );
            $claim = $claimer->claimPendingOrAbandoned();

            self::assertNotNull($claim, 'SKIP LOCKED must allow row 2 to be claimed.');
            self::assertSame($row2, $claim->id,
                'SKIP LOCKED must skip the row held by sibling session A.');

            // Row 1 still pending; row 2 now processing under B's identity.
            $stmt = $this->pdo->prepare(
                'SELECT status FROM stardust_export_jobs WHERE id = ?'
            );
            $stmt->execute([$row2]);
            self::assertSame('processing', $stmt->fetchColumn());
        } finally {
            if ($sibling->inTransaction()) {
                $sibling->rollBack();
            }
        }

        // After releasing the lock, row 1 is still pending.
        $stmt = $this->pdo->prepare(
            'SELECT status FROM stardust_export_jobs WHERE id = ?'
        );
        $stmt->execute([$row1]);
        self::assertSame('pending', $stmt->fetchColumn());

        // A second claimer call (no rows held) now picks up row 1.
        $claimer2 = new ExportJobClaimer(
            pdo: $this->pdo,
            clock: new SystemClock(),
            leaseTimeoutSeconds: 30,
        );
        $second = $claimer2->claimPendingOrAbandoned();
        self::assertNotNull($second);
        self::assertSame($row1, $second->id);
    }

    public function testTwoSiblingClaimersDoNotBothClaimSameRow(): void
    {
        // Variant: BOTH claimers use SKIP LOCKED on their own sessions.
        // Session A claims (commits), then session B claims — second
        // claim sees no rows because A already moved row 1 to processing.
        $sibling = $this->makeSiblingPdo();

        $modelId = $this->createModel(1, 'siblings');
        $this->createFieldNamed($modelId, 'k');
        $row1 = $this->seedExportJob(1, $modelId, 'pending', 'csv',
            createdAt: '2026-05-27 10:00:00');

        $claimerA = new ExportJobClaimer(
            pdo: $sibling, clock: new SystemClock(), leaseTimeoutSeconds: 30,
        );
        $claimerB = new ExportJobClaimer(
            pdo: $this->pdo, clock: new SystemClock(), leaseTimeoutSeconds: 30,
        );

        $a = $claimerA->claimPendingOrAbandoned();
        $b = $claimerB->claimPendingOrAbandoned();

        self::assertNotNull($a);
        self::assertSame($row1, $a->id);
        // B sees no pending and no abandoned (A's heartbeat is fresh)
        // — so B's claim returns null.
        self::assertNull($b, 'Second claimer must not double-claim the same row.');
    }

    private function makeSiblingPdo(): PDO
    {
        $dsn  = getenv('STARDUST_TEST_DSN') ?: '';
        $user = getenv('STARDUST_TEST_USER') ?: '';
        $pass = getenv('STARDUST_TEST_PASS') ?: '';

        if ($dsn === '' || $user === '') {
            self::markTestSkipped('STARDUST_TEST_DSN/STARDUST_TEST_USER must be set.');
        }

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
