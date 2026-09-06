<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use StarDust\Reconciler\TickOutcome;

/**
 * ADR 0020: one `correlation_id` per operation, carried through every
 * sub-event of that operation — including the ones emitted by a
 * different process, minutes or hours later.
 *
 * Each of the four asynchronous lifecycles has a synchronous initiator
 * that commits and returns, and a Reconciler work source that finishes
 * the job. Before `backfill_checkpoints.correlation_id` existed, the
 * completion half rode the **per-chunk** id, which changes every tick —
 * so an operator holding a `rename_started` line had nothing with which
 * to find the drain that finished it, or to learn that it never did.
 *
 * The assertions here come in pairs on purpose: same id across the seam,
 * *and* a `chunk_correlation_id` that differs from it. The second half is
 * what stops this passing for the wrong reason — a single-chunk drain
 * would satisfy "the ids match" if the code simply reused the chunk id
 * for both keys.
 *
 * See {@see Conventions\RegistryCorrelationTest} for the static guard on
 * the emit sites themselves, and why the logger's own synthesised id
 * makes that guard necessary.
 */
final class LifecycleCorrelationTest extends Phase6bTestCase
{
    public function testRenameStartAndCompleteShareOneId(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        for ($i = 1; $i <= 5; $i++) {
            $this->seedEntry(1, $modelId, ['title' => "v{$i}"]);
        }

        $initLog = $this->makeRecordingLogger();
        $this->makeRenameInitiator($initLog)->initiate(1, $fieldId, 'headline');

        // chunkSize 2 over 5 rows, so the drain spans several ticks and
        // the completion chunk is demonstrably not the first one.
        $drainLog = $this->makeRecordingLogger();
        $ticks = 0;
        while ($this->runRenameTick($drainLog, 2) === TickOutcome::WORK_DONE) {
            self::assertLessThan(10, ++$ticks, 'Backfill failed to terminate.');
        }
        self::assertGreaterThan(1, $ticks, 'Fixture must span more than one chunk.');

        $started  = $this->soleEvent($initLog->records(), 'rename_started');
        $complete = $this->soleEvent($drainLog->records(), 'rename_complete');

        $this->assertLifecycleJoin($started, $complete);
        self::assertSame('headline', $complete['new_name']);
    }

    public function testRetypeStartAndPromotionShareOneId(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'name');
        for ($i = 1; $i <= 5; $i++) {
            $this->seedEntry(1, $modelId, ['name' => "n{$i}"]);
        }

        $initLog = $this->makeRecordingLogger();
        $this->makeRetypeInitiator($initLog)->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: null,
            newIsFilterable: true,
        );

        $drainLog = $this->makeRecordingLogger();
        $source = $this->makeRetypeBackfillWorkSource($drainLog, 2);
        $ticks = 0;
        while ($source->tickOne('chunk-' . ++$ticks) === TickOutcome::WORK_DONE) {
            self::assertLessThan(10, $ticks, 'Backfill failed to terminate.');
        }
        self::assertGreaterThan(1, $ticks, 'Fixture must span more than one chunk.');

        $started  = $this->soleEvent($initLog->records(), 'retype_started');
        $promoted = $this->soleEvent($drainLog->records(), 'promote_to_ready');

        $this->assertLifecycleJoin($started, $promoted);
    }

    /**
     * The reservation is emitted from `SlotReserver`, not from the
     * initiator, and post-commit rather than inline — so it is the one
     * sub-event in this package that could most easily have been left
     * on its own id. It was.
     */
    public function testTheSlotReservedSubEventJoinsItsRetype(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'name');

        $log = $this->makeRecordingLogger();
        $this->makeRetypeInitiator($log)->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: null,
            newIsFilterable: true,
        );

        $started  = $this->soleEvent($log->records(), 'retype_started');
        $reserved = $this->soleEvent($log->records(), 'slot_reserved');

        self::assertSame(
            $started['correlation_id'],
            $reserved['correlation_id'],
            'slot_reserved is emitted inside the retype operation and must carry its id.',
        );
    }

    /**
     * ADR 0020 requires `tenant_id` "for any event tied to tenant-owned
     * data", and a slot assignment is. `page_provisioned` is the
     * deliberate exception — a page is global.
     */
    public function testSlotReservedCarriesItsTenant(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(7);
        $fieldId = $this->createField($modelId, 'string', true, 'name');

        $log = $this->makeRecordingLogger();
        $this->makeRetypeInitiator($log)->initiate(
            tenantId: 7,
            fieldId: $fieldId,
            newDeclaredType: null,
            newIsFilterable: true,
        );

        self::assertSame(7, $this->soleEvent($log->records(), 'slot_reserved')['tenant_id']);
    }

    public function testFieldDeleteStartAndCompleteShareOneId(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        for ($i = 1; $i <= 5; $i++) {
            $this->seedEntry(1, $modelId, ['title' => "v{$i}"]);
        }

        $initLog = $this->makeRecordingLogger();
        $this->makeDeleteFieldInitiator($initLog)->initiate(1, $fieldId);

        $drainLog = $this->makeRecordingLogger();
        $ticks = 0;
        while ($this->runDeleteTick($drainLog, 2) === TickOutcome::WORK_DONE) {
            self::assertLessThan(10, ++$ticks, 'Purge failed to terminate.');
        }
        self::assertGreaterThan(1, $ticks, 'Fixture must span more than one chunk.');

        $started  = $this->soleEvent($initLog->records(), 'delete_started');
        $complete = $this->soleEvent($drainLog->records(), 'delete_complete');

        $this->assertLifecycleJoin($started, $complete);
    }

    public function testModelDeleteStartAndCompleteShareOneId(): void
    {
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', false, 'title');
        for ($i = 1; $i <= 5; $i++) {
            $this->seedEntry(1, $modelId, ['title' => "v{$i}"]);
        }

        $initLog = $this->makeRecordingLogger();
        $this->makeDeleteModelInitiator($initLog)->initiate(1, $modelId);

        $drainLog = $this->makeRecordingLogger();
        $ticks = 0;
        while ($this->runModelPurgeTick($drainLog, 2) === TickOutcome::WORK_DONE) {
            self::assertLessThan(20, ++$ticks, 'Purge failed to terminate.');
        }
        self::assertGreaterThan(1, $ticks, 'Fixture must span more than one chunk.');

        $started  = $this->soleEvent($initLog->records(), 'model_delete_started');
        $complete = $this->soleEvent($drainLog->records(), 'model_delete_complete');

        $this->assertLifecycleJoin($started, $complete);
    }

    /**
     * The ADR 0019 and ADR 0031 advisories fire *because* a promotion
     * happened, and describe the state it produced — so they are
     * sub-events of the retype, not operations of their own.
     *
     * **This is the case the static scan cannot see, and it was missed
     * on the first pass for exactly that reason.** `CardinalitySampler`
     * and `SpreadSampler` both name `correlation_id` at their emit
     * sites, so `RegistryCorrelationTest` passed them — while they
     * minted fresh ids inside a promotion that already had one. Only an
     * assertion that two events share a *value* can tell the difference.
     */
    public function testThePostPromotionAdvisoriesJoinTheirRetype(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'name');
        $this->seedEntry(1, $modelId, ['name' => 'a']);

        $initLog = $this->makeRecordingLogger();
        $this->makeRetypeInitiator($initLog)->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: null,
            newIsFilterable: true,
        );

        $drainLog = $this->makeRecordingLogger();
        $source = $this->makeRetypeBackfillWorkSource($drainLog, 500);
        $ticks = 0;
        while ($source->tickOne('chunk-' . ++$ticks) === TickOutcome::WORK_DONE) {
            self::assertLessThan(10, $ticks, 'Backfill failed to terminate.');
        }

        $records     = $drainLog->records();
        $lifecycleId = $this->soleEvent($initLog->records(), 'retype_started')['correlation_id'];

        self::assertSame(
            $lifecycleId,
            $this->soleEvent($records, 'promote_to_ready')['correlation_id'],
        );
        self::assertSame(
            $lifecycleId,
            $this->soleEvent($records, 'cardinality_sampled')['correlation_id'],
            'The ADR 0019 post-backfill advisory is a sub-event of the retype.',
        );
        self::assertSame(
            $lifecycleId,
            $this->soleEvent($records, 'spread_sampled')['correlation_id'],
            'The ADR 0031 post-relocation advisory is a sub-event of the retype.',
        );
    }

    /**
     * A second lifecycle on the same field must not inherit the first
     * one's id — the checkpoint row is reused, so the `ON DUPLICATE KEY
     * UPDATE` has to reset the column along with everything else. This
     * is the same class of trap as `source_declared_type`, and it fails
     * silently: the completion event would join a `rename_started` that
     * described a different rename.
     */
    public function testASecondRenameDoesNotInheritTheFirstsId(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        $this->seedEntry(1, $modelId, ['title' => 'v1']);

        $firstLog = $this->makeRecordingLogger();
        $this->makeRenameInitiator($firstLog)->initiate(1, $fieldId, 'headline');
        while ($this->runRenameTick() === TickOutcome::WORK_DONE) {
        }

        $secondLog = $this->makeRecordingLogger();
        $this->makeRenameInitiator($secondLog)->initiate(1, $fieldId, 'caption');

        $drainLog = $this->makeRecordingLogger();
        while ($this->runRenameTick($drainLog) === TickOutcome::WORK_DONE) {
        }

        $first    = $this->soleEvent($firstLog->records(), 'rename_started');
        $second   = $this->soleEvent($secondLog->records(), 'rename_started');
        $complete = $this->soleEvent($drainLog->records(), 'rename_complete');

        self::assertNotSame($first['correlation_id'], $second['correlation_id']);
        self::assertSame(
            $second['correlation_id'],
            $complete['correlation_id'],
            'The completion must join the rename that actually produced it.',
        );
    }

    /**
     * A checkpoint opened before the column existed reads back null, and
     * the work source must fall back to the chunk id rather than emit a
     * null `correlation_id` — ADR 0020 types the field as non-nullable.
     * Simulated by clearing the column, which is exactly the state an
     * in-flight rename would be in when the ALTER lands under it.
     */
    public function testAPreExistingCheckpointFallsBackToTheChunkId(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        $this->seedEntry(1, $modelId, ['title' => 'v1']);

        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');
        $this->pdo->exec('UPDATE backfill_checkpoints SET correlation_id = NULL');

        $drainLog = $this->makeRecordingLogger();
        while ($this->runRenameTick($drainLog) === TickOutcome::WORK_DONE) {
        }

        $complete = $this->soleEvent($drainLog->records(), 'rename_complete');

        self::assertIsString($complete['correlation_id']);
        self::assertNotSame('', $complete['correlation_id']);
        self::assertSame(
            $complete['chunk_correlation_id'],
            $complete['correlation_id'],
            'With no stored id the event must fall back to the chunk id, not to null.',
        );
    }

    /**
     * @param array<string,mixed> $started
     * @param array<string,mixed> $complete
     */
    private function assertLifecycleJoin(array $started, array $complete): void
    {
        self::assertIsString($started['correlation_id']);
        self::assertSame(
            $started['correlation_id'],
            $complete['correlation_id'],
            'The two halves of one lifecycle must carry the same correlation_id.',
        );
        self::assertNotSame(
            $complete['correlation_id'],
            $complete['chunk_correlation_id'],
            'The chunk id must survive under its own key, and must not be the'
            . ' value being asserted above — otherwise this test passes for a'
            . ' drain that reused the chunk id for both.',
        );
    }

    /**
     * The one event of that name, with its context flattened.
     *
     * @param  list<array{level: string, message: string, context: array<string,mixed>}> $records
     * @return array<string,mixed>
     */
    private function soleEvent(array $records, string $eventName): array
    {
        $matches = $this->recordsWithEvent($records, $eventName);

        self::assertCount(
            1,
            $matches,
            "Expected exactly one `{$eventName}` event, got " . count($matches) . '.',
        );

        return $matches[0]['context'];
    }
}
