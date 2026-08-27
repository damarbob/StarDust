<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Retype;

use PDO;
use PDOException;
use StarDust\Exception\IncompatibleRetypeException;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * Two-session proof that {@see \StarDust\Retype\RetypeInitiator} reads
 * `stardust_fields` under a **locking** read, not a snapshot one.
 *
 * ## What the lock is for
 *
 * `RetypeCheckpointRepository::insertOrReset()` is an upsert, so a
 * second initiator no longer collides on `ux_backfill_job_name` — it
 * overwrites. Two initiators on one field would otherwise each read
 * `declared_type` from their own snapshot before either committed, and
 * the loser would stamp a stale `source_declared_type` onto the
 * checkpoint. `RetypeBackfillWorkSource` reads that to choose the ADR
 * 0024 matrix cell, so the second backfill would coerce through the
 * wrong one — silently, with no event and no exception. `loadField()`
 * therefore runs `FOR UPDATE OF f` inside the initiator's transaction,
 * which makes the loser block and then re-read what the winner
 * committed.
 *
 * ## Why this asserts on an *incompatible* retype
 *
 * The obvious test — hold the row, run a normal retype, expect a
 * lock-wait timeout — **cannot fail**, and was written and discarded
 * before this one. Measured on MySQL 8.0.13: the reservation step's
 * `UPDATE stardust_slot_assignments SET field_id = ?` takes an FK shared
 * lock on the parent `stardust_fields` row (`fk_slot_assignments_field`),
 * so a sibling's `FOR UPDATE` blocks any filterable-target initiation at
 * step 3 whether or not `loadField()` locks anything. Both variants
 * time out; the assertion proves nothing.
 *
 * An ADR 0024 categorical rejection is the one shape that resolves
 * *between* the two points. It throws immediately after `loadField()`
 * and never reaches the reservation, so the read is the only statement
 * that can contend:
 *
 *   - locking read   → blocks on the sibling → `PDOException` 1205
 *   - snapshot read  → proceeds → `IncompatibleRetypeException`
 *
 * Different exception types, so the fixture discriminates. Validated by
 * removing `FOR UPDATE OF f` and confirming this test fails.
 */
final class RetypeInitiatorConcurrencyTest extends Phase6bTestCase
{
    /** The statement `RetypeInitiator::loadField()` issues, verbatim. */
    private const LOCK_FIELD_SQL =
        'SELECT f.declared_type, f.is_filterable, f.model_id, f.deleted_at, m.tenant_id'
        . ' FROM stardust_fields f'
        . ' JOIN stardust_models m ON m.id = f.model_id'
        . ' WHERE f.id = ?'
        . ' FOR UPDATE OF f';

    public function testTheFieldReadBlocksBehindASiblingLockOnTheSameRow(): void
    {
        $fieldId = $this->seedIntField();
        $sibling = $this->makeSiblingPdo();

        try {
            $sibling->beginTransaction();
            $held = $sibling->prepare(self::LOCK_FIELD_SQL);
            $held->execute([$fieldId]);
            $held->fetchAll(PDO::FETCH_ASSOC); // drain, so the lock is held to COMMIT

            // Shorten the wait so the suite does not stall on the 50 s default.
            $this->pdo->exec('SET innodb_lock_wait_timeout = 1');

            $caught = null;
            try {
                // int -> datetime is categorically rejected (ADR 0024).
                // Reaching that guard at all means the read got through.
                $this->makeRetypeInitiator()->initiate(
                    tenantId: 1,
                    fieldId: $fieldId,
                    newDeclaredType: 'datetime',
                    newIsFilterable: null,
                );
            } catch (PDOException | IncompatibleRetypeException $e) {
                $caught = $e;
            } finally {
                $this->pdo->exec('SET innodb_lock_wait_timeout = DEFAULT');
            }

            self::assertNotInstanceOf(
                IncompatibleRetypeException::class,
                $caught,
                'The field read reached the ADR 0024 guard, so it was a snapshot read'
                . ' and did not serialise behind the sibling.',
            );
            self::assertInstanceOf(PDOException::class, $caught);

            $info = $caught->errorInfo ?? [];
            self::assertTrue(
                (isset($info[1]) && (int) $info[1] === 1205)
                || str_contains($caught->getMessage(), 'Lock wait timeout'),
                'Expected SQLSTATE 1205 (lock wait timeout); got: ' . $caught->getMessage(),
            );

            // The refused attempt leaves nothing behind.
            self::assertNull($this->fetchCheckpointForField($fieldId));
            self::assertSame('int', $this->fetchFieldRow($fieldId)['declared_type']);
        } finally {
            if ($sibling->inTransaction()) {
                $sibling->rollBack();
            }
        }
    }

    /**
     * The converse, so the test above cannot pass by asserting that the
     * call is simply broken: with nobody holding the row, the identical
     * call reaches the ADR 0024 guard it is supposed to reach.
     */
    public function testTheSameCallReachesTheAdr0024GuardWithNoSiblingHoldingTheRow(): void
    {
        $fieldId = $this->seedIntField();

        $this->expectException(IncompatibleRetypeException::class);
        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'datetime',
            newIsFilterable: null,
        );
    }

    /** A filterable `int` field holding a live slot. */
    private function seedIntField(): int
    {
        $this->provisionPage(['i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'int', true, 'count');
        $this->reserveSlotFor($fieldId);

        return $fieldId;
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
