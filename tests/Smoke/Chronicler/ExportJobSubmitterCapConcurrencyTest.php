<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use PDO;
use PDOException;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Export\ExportJobRequest;
use StarDust\Export\ExportJobSubmitter;
use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * Two-session proof that the per-tenant cap check is serialized by
 * `SELECT … FOR UPDATE` and not racy:
 *
 *   Session A holds a `SELECT FOR UPDATE` on the tenant's active-job
 *   range (the exact range the submitter locks). Session B's submitter
 *   then attempts to acquire the same lock — with
 *   `innodb_lock_wait_timeout = 1` on B, the second submission
 *   surfaces an `SQLSTATE 1205` ("lock wait timeout exceeded") within
 *   the timeout window rather than silently inserting a phantom row.
 *
 *   This proves the FOR UPDATE predicate is actually serialising
 *   concurrent submissions — the cap check + INSERT are atomic per
 *   ADR 0010 / the chronicler_daemon.md cap requirement.
 *
 *   After A commits, a fresh submission on B at the cap limit
 *   correctly throws `ExportJobActiveCapExceededException` — proving
 *   the cap state is visible across sessions once the gap lock is
 *   released.
 */
final class ExportJobSubmitterCapConcurrencyTest extends Phase7TestCase
{
    public function testSiblingSessionSelectForUpdateBlocksConcurrentSubmission(): void
    {
        $sibling = $this->makeSiblingPdo();

        $modelId = $this->createModel(1, 'cap_lock');
        $this->createFieldNamed($modelId, 'k');

        // Seed one pending row so the tenant has one active job. The
        // submitter's gap-lock SELECT covers `(tenant_id, status IN
        // ('pending', 'processing'))`.
        $this->seedExportJob(1, $modelId, 'pending', 'csv');

        try {
            // Session A: acquire the same lock the submitter would.
            $sibling->beginTransaction();
            $aStmt = $sibling->prepare(
                'SELECT id FROM stardust_export_jobs'
                . " WHERE tenant_id = ? AND status IN ('pending','processing')"
                . ' FOR UPDATE'
            );
            $aStmt->execute([1]);
            // Drain the result so the lock is held until we COMMIT.
            $aStmt->fetchAll(PDO::FETCH_COLUMN);

            // Session B: shorten its lock-wait so the test does not
            // hang for the default 50 s.
            $this->pdo->exec('SET innodb_lock_wait_timeout = 1');

            $submitter = new ExportJobSubmitter(
                pdo: $this->pdo,
                clock: new SystemClock(),
                logger: new NullLogger(),
                perTenantActiveCap: 3, // plenty of headroom — proves lock blocks, not cap
            );

            $threw = false;
            try {
                $submitter->submit(new ExportJobRequest(1, $modelId, 'csv'));
            } catch (PDOException $e) {
                $threw = true;
                $info = $e->errorInfo ?? [];
                // SQLSTATE for lock-wait-timeout is HY000 with driver
                // code 1205. Either piece of metadata is acceptable —
                // some PDO drivers populate them differently.
                self::assertTrue(
                    (isset($info[1]) && (int) $info[1] === 1205)
                    || str_contains($e->getMessage(), 'Lock wait timeout'),
                    'Expected SQLSTATE 1205 (lock wait timeout); got: ' . $e->getMessage(),
                );
            } finally {
                // Restore default for any subsequent test sharing this PDO.
                $this->pdo->exec('SET innodb_lock_wait_timeout = DEFAULT');
            }

            self::assertTrue($threw, 'Submitter must not silently insert while sibling holds FOR UPDATE.');
        } finally {
            if ($sibling->inTransaction()) {
                $sibling->rollBack();
            }
        }
    }

    public function testCapIsVisibleToSiblingSessionAfterCommit(): void
    {
        // Session A submits to the cap; session B (sibling PDO) tries
        // one more and observes the cap — proves the gap lock + commit
        // protocol makes the cap correctly visible across sessions.
        $sibling = $this->makeSiblingPdo();

        $modelId = $this->createModel(1, 'cap_visible');
        $this->createFieldNamed($modelId, 'k');

        $submitterA = new ExportJobSubmitter(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new NullLogger(),
            perTenantActiveCap: 2,
        );
        $submitterB = new ExportJobSubmitter(
            pdo: $sibling,
            clock: new SystemClock(),
            logger: new NullLogger(),
            perTenantActiveCap: 2,
        );

        $submitterA->submit(new ExportJobRequest(1, $modelId, 'csv'));
        $submitterA->submit(new ExportJobRequest(1, $modelId, 'csv'));

        $this->expectException(\StarDust\Exception\ExportJobActiveCapExceededException::class);
        $submitterB->submit(new ExportJobRequest(1, $modelId, 'csv'));
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
