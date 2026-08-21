<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Schema;

use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Config\Config;
use StarDust\Schema\ModelDescription;
use StarDust\StarDust;
use StarDust\Tests\Smoke\ReadPathTestCase;

/**
 * `StarDust::listModels()` / `describeModel()` — registry introspection.
 *
 * The distinction these tests exist to protect is
 * `isFilterable` vs `isIndexed`. The registry flag records intent; the
 * live slot records whether a filter will actually work. They diverge
 * for the whole of a promotion or retype backfill, and while a newly
 * registered filterable field waits on Watcher capacity — and a caller
 * that gates a "filter by this field" UI on the wrong one produces
 * `FieldNotIndexedException` at query time (ADR 0004).
 */
final class SchemaReaderTest extends ReadPathTestCase
{
    private function engine(): StarDust
    {
        return new StarDust(new Config(
            pdo: $this->pdo,
            logger: new NullLogger(),
            clock: new SystemClock(),
        ));
    }

    // ---------------------------------------------------------------
    // listModels()
    // ---------------------------------------------------------------

    public function testListsOnlyTheTenantsOwnModels(): void
    {
        $mine = $this->createModel(1, 'invoice');
        $alsoMine = $this->createModel(1, 'company');
        $theirs = $this->createModel(2, 'secret');

        $models = $this->engine()->listModels(1);

        $ids = array_map(static fn ($m): int => $m->modelId, $models);
        self::assertSame([$mine, $alsoMine], $ids, 'Ordered by id, and scoped to the tenant.');
        self::assertNotContains($theirs, $ids);
        self::assertSame('invoice', $models[0]->name);
    }

    public function testListingATenantWithNoModelsReturnsAnEmptyList(): void
    {
        $this->createModel(2, 'theirs');

        self::assertSame([], $this->engine()->listModels(1));
    }

    // ---------------------------------------------------------------
    // describeModel()
    // ---------------------------------------------------------------

    public function testDescribesFieldsInRegistrationOrderWithTheirDeclaredTypes(): void
    {
        $modelId = $this->createModel(1, 'company');
        $this->createField($modelId, 'string', false, 'name');
        $this->createField($modelId, 'int', false, 'employees');

        $description = $this->engine()->describeModel(1, $modelId);

        self::assertInstanceOf(ModelDescription::class, $description);
        self::assertSame('company', $description->name);
        self::assertCount(2, $description->fields);
        self::assertSame('name', $description->fields[0]->name);
        self::assertSame('string', $description->fields[0]->declaredType);
        self::assertSame('employees', $description->fields[1]->name);
        self::assertSame('int', $description->fields[1]->declaredType);
    }

    public function testDescribingAnotherTenantsModelReturnsNull(): void
    {
        $theirs = $this->createModel(2, 'secret');

        self::assertNull($this->engine()->describeModel(1, $theirs));
    }

    public function testDescribingAMissingModelReturnsNull(): void
    {
        self::assertNull($this->engine()->describeModel(1, 999_999));
    }

    public function testFieldLookupByName(): void
    {
        $modelId = $this->createModel(1, 'company');
        $this->createField($modelId, 'string', false, 'name');

        $description = $this->engine()->describeModel(1, $modelId);

        self::assertNotNull($description);
        self::assertSame('name', $description->field('name')?->name);
        self::assertNull($description->field('nope'));
    }

    // ---------------------------------------------------------------
    // isFilterable vs isIndexed — the reason this DTO has two flags
    // ---------------------------------------------------------------

    public function testAReservedFilterableFieldIsBothFilterableAndIndexed(): void
    {
        [$modelId, , , $fieldName] = $this->setupFilterableStringField(1);

        $field = $this->engine()->describeModel(1, $modelId)?->field($fieldName);

        self::assertNotNull($field);
        self::assertTrue($field->isFilterable);
        self::assertTrue($field->isIndexed);
    }

    public function testAJsonOnlyFieldIsNeitherFilterableNorIndexed(): void
    {
        $modelId = $this->createModel(1, 'company');
        $this->createField($modelId, 'string', false, 'note');

        $field = $this->engine()->describeModel(1, $modelId)?->field('note');

        self::assertNotNull($field);
        self::assertFalse($field->isFilterable);
        self::assertFalse($field->isIndexed);
    }

    /**
     * The divergence that matters: registered as filterable, but no
     * slot has been reserved yet, so a filter would be rejected.
     */
    public function testAFilterableFieldAwaitingCapacityIsFilterableButNotIndexed(): void
    {
        $modelId = $this->createModel(1, 'company');
        $this->createField($modelId, 'string', true, 'industry');

        $field = $this->engine()->describeModel(1, $modelId)?->field('industry');

        self::assertNotNull($field);
        self::assertTrue($field->isFilterable, 'The intent is recorded...');
        self::assertFalse($field->isIndexed, '...but nothing can filter on it yet.');
    }

    /** `backfilling` is live for the write path but not queryable for the read path. */
    public function testABackfillingSlotDoesNotCountAsIndexed(): void
    {
        [$modelId, $fieldId, , $fieldName] = $this->setupFilterableStringField(1);
        $this->pdo->exec(
            "UPDATE stardust_slot_assignments SET status = 'backfilling' WHERE field_id = {$fieldId}"
        );

        $field = $this->engine()->describeModel(1, $modelId)?->field($fieldName);

        self::assertNotNull($field);
        self::assertTrue($field->isFilterable);
        self::assertFalse($field->isIndexed, 'ADR 0004 rejects filters against a backfilling slot.');
    }

    public function testIndexedFieldsReturnsOnlyTheQueryableOnes(): void
    {
        [$modelId, , , $reserved] = $this->setupFilterableStringField(1);
        $this->createField($modelId, 'string', true, 'awaiting_capacity');
        $this->createField($modelId, 'string', false, 'json_only');

        $description = $this->engine()->describeModel(1, $modelId);

        self::assertNotNull($description);
        self::assertCount(3, $description->fields);

        $names = array_map(static fn ($f): string => $f->name, $description->indexedFields());
        self::assertSame([$reserved], $names);
    }

    // ---------------------------------------------------------------
    // Reflecting a lifecycle change
    // ---------------------------------------------------------------

    /**
     * Demotion is registry-only and tombstone-and-done, so the field
     * stops being either filterable or indexed as soon as it returns —
     * no backfill window to wait through.
     */
    public function testDemotionIsReflectedImmediately(): void
    {
        [$modelId, $fieldId, , $fieldName] = $this->setupFilterableStringField(1);

        $engine = $this->engine();
        self::assertTrue($engine->describeModel(1, $modelId)?->field($fieldName)?->isIndexed);

        $engine->demoteFieldFromFilterable(1, $fieldId);

        $field = $engine->describeModel(1, $modelId)?->field($fieldName);
        self::assertNotNull($field);
        self::assertFalse($field->isFilterable);
        self::assertFalse($field->isIndexed);
    }
}
