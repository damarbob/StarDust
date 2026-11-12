<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Schema;

use PDOException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Config\Config;
use StarDust\Schema\FieldDefinition;
use StarDust\Schema\SchemaBuilder;
use StarDust\Schema\SchemaReader;
use StarDust\Slot\SlotReserver;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase6bTestCase;
use StarDust\Tests\Smoke\Support\LockFailureInjectingPdo;
use StarDust\Tests\Smoke\Support\StatementRecordingPdo;
use StarDust\Tests\Smoke\Support\TestPdo;
use StarDust\Write\EntryPayload;

/**
 * A filterable field registered on a model with no entries is born with its
 * slot, and every other registration is left as it was.
 *
 * The cases that matter are the ones that must NOT reserve. A populated
 * model, a model with no free indexed slot, an existing field, a builder
 * with no reserver and the switched-off setting all register the field
 * with no slot, and each has its own test.
 */
final class EagerSlotRegistrationTest extends Phase6bTestCase
{
    private function builder(?LoggerInterface $logger = null, bool $withReserver = true): SchemaBuilder
    {
        $logger ??= new NullLogger();

        return new SchemaBuilder(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: $logger,
            slotReserver: $withReserver
                ? new SlotReserver(pdo: $this->pdo, clock: new SystemClock(), logger: $logger)
                : null,
        );
    }

    private function schemaVersion(): int
    {
        return (int) $this->pdo
            ->query('SELECT version FROM stardust_schema_version WHERE id = 1')
            ->fetchColumn();
    }

    public function testFilterableFieldsOnANewModelGetAssignedIndexedSlots(): void
    {
        $this->provisionPage(['i_str_01', 'i_int_01']);
        $before = $this->schemaVersion();

        $model = $this->builder()->createModel(1, 'company', [
            new FieldDefinition('name', 'string', isFilterable: true),
            new FieldDefinition('employees', 'int', isFilterable: true),
            new FieldDefinition('notes', 'string'),
        ]);

        $name = $this->fetchLiveSlotForField($model->fieldId('name'));
        $employees = $this->fetchLiveSlotForField($model->fieldId('employees'));

        self::assertNotNull($name);
        self::assertSame('assigned', $name['status']);
        self::assertSame('i_str_01', $name['slot_column']);
        self::assertNotNull($employees);
        self::assertSame('assigned', $employees['status']);
        self::assertSame('i_int_01', $employees['slot_column']);

        // A non-filterable field is JSON-only (ADR 0034) and gets nothing.
        self::assertNull($this->fetchLiveSlotForField($model->fieldId('notes')));

        // One bump for the whole call, however many slots it reserved.
        self::assertSame($before + 1, $this->schemaVersion());

        // The point of the change: the field is queryable now.
        $description = (new SchemaReader($this->pdo))->describeModel(1, $model->modelId);
        self::assertNotNull($description);
        self::assertTrue($description->field('name')?->isIndexed);
        self::assertTrue($description->field('employees')?->isIndexed);
        self::assertFalse($description->field('notes')?->isIndexed);
    }

    public function testDefineFieldOnAnEmptyExistingModelGetsASlot(): void
    {
        $this->provisionPage(['i_str_01']);
        $builder = $this->builder();
        $modelId = $builder->defineModel(7, 'invoice');
        $before  = $this->schemaVersion();

        $fieldId = $builder->defineField($modelId, 'reference', 'string', isFilterable: true);

        $slot = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($slot);
        self::assertSame('assigned', $slot['status']);
        self::assertSame($before + 1, $this->schemaVersion());
    }

    public function testTheFirstWriteFillsTheSlotWithNoQueueRow(): void
    {
        $this->provisionPage(['i_str_01']);
        $model = $this->builder()->createModel(1, 'article', [
            new FieldDefinition('title', 'string', isFilterable: true),
        ]);

        $this->makeEntryWriter()->write(new EntryPayload(
            tenantId: 1,
            modelId: $model->modelId,
            fields: ['title' => 'Hello'],
        ));

        $slot = $this->fetchLiveSlotForField($model->fieldId('title'));
        self::assertNotNull($slot);
        $table = (string) $this->pdo
            ->query('SELECT table_name FROM stardust_pages WHERE id = ' . (int) $slot['page_id'])
            ->fetchColumn();
        self::assertSame('Hello', $this->pdo->query("SELECT i_str_01 FROM {$table}")->fetchColumn());

        // Written in the write transaction, so nothing waits for a Reconciler.
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_sync_queue')->fetchColumn());
    }

    public function testNoFreeIndexedSlotRegistersWithoutOne(): void
    {
        $before = $this->schemaVersion();

        $model = $this->builder()->createModel(1, 'company', [
            new FieldDefinition('name', 'string', isFilterable: true),
        ]);

        self::assertGreaterThan(0, $model->fieldId('name'));
        self::assertNull($this->fetchLiveSlotForField($model->fieldId('name')));
        self::assertSame($before + 1, $this->schemaVersion());
    }

    public function testAFamilyWithNoFreeIndexedSlotLeavesOnlyThatFieldWithoutOne(): void
    {
        $this->provisionPage(['i_int_01']);

        $model = $this->builder()->createModel(1, 'company', [
            new FieldDefinition('name', 'string', isFilterable: true),
            new FieldDefinition('employees', 'int', isFilterable: true),
        ]);

        self::assertNull($this->fetchLiveSlotForField($model->fieldId('name')));
        self::assertNotNull($this->fetchLiveSlotForField($model->fieldId('employees')));
    }

    public function testAModelThatHasEntriesIsLeftLazy(): void
    {
        $this->provisionPage(['i_str_01']);
        $model = $this->builder()->createModel(1, 'article', [
            new FieldDefinition('body', 'string'),
        ]);
        $this->makeEntryWriter()->write(new EntryPayload(
            tenantId: 1,
            modelId: $model->modelId,
            fields: ['body' => 'text', 'title' => 'carried before the field exists'],
        ));

        $fieldId = $this->builder()->defineField($model->modelId, 'title', 'string', isFilterable: true);

        self::assertNull($this->fetchLiveSlotForField($fieldId));
    }

    public function testASoftDeletedEntryStillCounts(): void
    {
        $this->provisionPage(['i_str_01']);
        $model = $this->builder()->createModel(1, 'article', [
            new FieldDefinition('body', 'string'),
        ]);
        $this->makeEntryWriter()->write(new EntryPayload(
            tenantId: 1,
            modelId: $model->modelId,
            fields: ['body' => 'text'],
        ));
        $this->pdo->exec('UPDATE entry_data SET deleted_at = UTC_TIMESTAMP()');

        $fieldId = $this->builder()->defineField($model->modelId, 'title', 'string', isFilterable: true);

        self::assertNull($this->fetchLiveSlotForField($fieldId));
    }

    public function testAnotherTenantsEntriesDoNotCount(): void
    {
        $this->provisionPage(['i_str_01']);
        $other = $this->builder()->createModel(2, 'article', [new FieldDefinition('body', 'string')]);
        $this->makeEntryWriter()->write(new EntryPayload(
            tenantId: 2,
            modelId: $other->modelId,
            fields: ['body' => 'text'],
        ));

        $model = $this->builder()->createModel(1, 'article', [
            new FieldDefinition('title', 'string', isFilterable: true),
        ]);

        self::assertNotNull($this->fetchLiveSlotForField($model->fieldId('title')));
    }

    public function testAnExistingFieldIsNeverMigrated(): void
    {
        $first = $this->builder()->createModel(1, 'company', [
            new FieldDefinition('name', 'string', isFilterable: true),
        ]);
        self::assertNull($this->fetchLiveSlotForField($first->fieldId('name')));

        // Capacity appears later. Re-running the registration finds the field
        // and returns it unchanged, so nothing is reserved.
        $this->provisionPage(['i_str_01']);
        $again = $this->builder()->createModel(1, 'company', [
            new FieldDefinition('name', 'string', isFilterable: true),
        ]);

        self::assertSame($first->fieldId('name'), $again->fieldId('name'));
        self::assertNull($this->fetchLiveSlotForField($again->fieldId('name')));
    }

    public function testABuilderWithNoReserverBehavesAsBefore(): void
    {
        $this->provisionPage(['i_str_01']);

        $model = $this->builder(withReserver: false)->createModel(1, 'company', [
            new FieldDefinition('name', 'string', isFilterable: true),
        ]);

        self::assertNull($this->fetchLiveSlotForField($model->fieldId('name')));
    }

    public function testTheConfigSettingDecidesWhatTheFacadeBuilds(): void
    {
        $this->provisionPage(['i_str_01', 'i_str_02']);

        $on = new StarDust(new Config(pdo: $this->pdo, logger: new NullLogger()));
        $off = new StarDust(new Config(
            pdo: $this->pdo,
            logger: new NullLogger(),
            reserveSlotAtRegistration: false,
        ));

        $withSlot = $on->schemaBuilder()->createModel(1, 'on', [
            new FieldDefinition('a', 'string', isFilterable: true),
        ]);
        $without = $off->schemaBuilder()->createModel(1, 'off', [
            new FieldDefinition('a', 'string', isFilterable: true),
        ]);

        self::assertTrue((new Config(pdo: $this->pdo, logger: new NullLogger()))->reserveSlotAtRegistration);
        self::assertNotNull($this->fetchLiveSlotForField($withSlot->fieldId('a')));
        self::assertNull($this->fetchLiveSlotForField($without->fieldId('a')));
    }

    public function testEachReservationEmitsSlotReservedUnderOneCorrelationId(): void
    {
        $this->provisionPage(['i_str_01', 'i_int_01']);
        $logger = $this->makeRecordingLogger();

        $this->builder($logger)->createModel(5, 'company', [
            new FieldDefinition('name', 'string', isFilterable: true),
            new FieldDefinition('employees', 'int', isFilterable: true),
        ]);

        $events = $this->recordsWithEvent($logger->records(), 'slot_reserved');
        self::assertCount(2, $events);
        foreach ($events as $event) {
            self::assertSame('assigned', $event['context']['status']);
            self::assertSame(5, $event['context']['tenant_id']);
        }
        self::assertSame($events[0]['context']['correlation_id'], $events[1]['context']['correlation_id']);
    }

    public function testNoEventIsEmittedWhenNothingWasReserved(): void
    {
        $logger = $this->makeRecordingLogger();

        $this->builder($logger)->createModel(1, 'company', [
            new FieldDefinition('name', 'string', isFilterable: true),
        ]);

        self::assertSame([], $this->recordsWithEvent($logger->records(), 'slot_reserved'));
    }

    public function testAFailedReservationRollsBackTheWholeRegistration(): void
    {
        $this->provisionPage(['i_str_01']);
        $before = $this->schemaVersion();

        // The claiming UPDATE of the reservation hits a lock timeout.
        $failing = LockFailureInjectingPdo::wrap($this->pdo, 'SET status = ?, field_id = ?', 1);
        $builder = new SchemaBuilder(
            pdo: $failing,
            clock: new SystemClock(),
            logger: new NullLogger(),
            slotReserver: new SlotReserver($failing, new SystemClock(), new NullLogger()),
        );

        try {
            $builder->createModel(1, 'company', [
                new FieldDefinition('name', 'string', isFilterable: true),
            ]);
            self::fail('The lock failure should have reached the caller.');
        } catch (PDOException $e) {
            self::assertSame(0, $failing->remainingFailures());
        }

        // Nothing of the registration survives, so the caller can retry it.
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM stardust_models WHERE name = 'company'")->fetchColumn(),
        );
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_fields')->fetchColumn());
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM stardust_slot_assignments WHERE status <> 'free'")->fetchColumn(),
        );
        self::assertSame($before, $this->schemaVersion());

        // The retry succeeds.
        $model = $this->builder()->createModel(1, 'company', [
            new FieldDefinition('name', 'string', isFilterable: true),
        ]);
        self::assertNotNull($this->fetchLiveSlotForField($model->fieldId('name')));
    }

    public function testTheReservationAddsAFixedFourStatementsPerFilterableField(): void
    {
        $this->provisionPage(['i_str_01', 'i_str_02', 'i_str_03', 'i_str_04']);

        // Statements one createModel() call sends, with or without the
        // reserver. The model is new, so the emptiness probe never runs.
        $count = function (int $fields, bool $withReserver): int {
            $pdo = new StatementRecordingPdo(
                getenv('STARDUST_TEST_DSN') ?: '',
                getenv('STARDUST_TEST_USER') ?: '',
                getenv('STARDUST_TEST_PASS') ?: '',
                TestPdo::options(),
            );
            $definitions = [];
            for ($i = 1; $i <= $fields; $i++) {
                $definitions[] = new FieldDefinition('f' . $i, 'string', isFilterable: true);
            }
            (new SchemaBuilder(
                pdo: $pdo,
                clock: new SystemClock(),
                logger: new NullLogger(),
                slotReserver: $withReserver
                    ? new SlotReserver($pdo, new SystemClock(), new NullLogger())
                    : null,
            ))->createModel(1, 'm' . $fields . ($withReserver ? 'r' : 'p'), $definitions);

            return count($pdo->statements);
        };

        // Resolve the field, read the model's pages, pick a candidate, claim
        // it. That is four however many fields come before, because the
        // candidate search stops at the first affine hit.
        foreach ([1, 2, 3] as $fields) {
            self::assertSame(
                4 * $fields,
                $count($fields, true) - $count($fields, false),
                "{$fields} filterable field(s)",
            );
        }
    }
}
