<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Retype;

use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * A same-type retype is a pure slot relocation.
 *
 * This is not an interesting retype in its own right — it is the
 * foundation ADR 0033 model compaction is built on. That operation
 * relocates a model's slots onto a minimal page set as a *sequence of
 * same-type retypes*, riding this pipeline unmodified: the ADR 0024
 * coercion matrix short-circuits its identity diagonal, so the value
 * passes through and the only real effect is that the field ends up on
 * a different slot column.
 *
 * Nothing tested that end to end before compaction was designed, which
 * meant the whole cure rested on an untested property of the cure's
 * dependencies. A change to `RetypeInitiator`'s same-type handling, to
 * `RetypeCoercionEngine::isCategoricallyRejected()`, or to the work
 * source's chunk loop could have broken compaction silently. These
 * tests exist so that fails here instead.
 */
final class SameTypeRelocationTest extends Phase6bTestCase
{
    /**
     * The load-bearing property: initiate a same-type retype, drain the
     * unmodified Reconciler, and every value must arrive intact on a
     * *different* slot column with the field filterable again.
     */
    public function testSameTypeRetypeRelocatesEveryValueToANewSlot(): void
    {
        // Two indexed string slots so the replacement has somewhere to go.
        $pageId  = $this->provisionPage(['i_str_01', 'i_str_02']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'code');
        $this->reserveSlotFor($fieldId);

        $values = ['A-1', 'B-2', 'C-3'];
        $entryIds = [];
        foreach ($values as $value) {
            $entryIds[] = $this->seedEntry(1, $modelId, ['code' => $value]);
        }

        $originalSlot = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($originalSlot);
        $originalColumn = (string) $originalSlot['slot_column'];
        $tableName = $this->pageTableNameFor($pageId);

        // Same type in, same type out — no `stardust_fields` shape change.
        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'string',
            newIsFilterable: null,
        );

        $newSlot = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($newSlot, 'A same-type retype must still reserve a replacement slot.');
        $newColumn = (string) $newSlot['slot_column'];
        self::assertNotSame(
            $originalColumn,
            $newColumn,
            'The point of a relocation is that the field moves to a different column.',
        );
        self::assertSame('backfilling', (string) $newSlot['status']);

        // The checkpoint records the source type, which is what routes
        // the backfill through ADR 0024's identity diagonal.
        $checkpoint = $this->fetchCheckpointForField($fieldId);
        self::assertNotNull($checkpoint);
        self::assertSame('running', (string) $checkpoint['status']);
        self::assertSame('string', (string) $checkpoint['source_declared_type']);

        $this->makeRetypeBackfillWorkSource()->tickOne('test-same-type');

        // Promoted, checkpoint closed, and the field is filterable again.
        $promoted = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($promoted);
        self::assertSame('ready', (string) $promoted['status']);
        self::assertSame('completed', (string) $this->fetchCheckpointForField($fieldId)['status']);

        // Every value survived the move, in order.
        foreach ($values as $i => $expected) {
            self::assertSame(
                $expected,
                (string) $this->fetchSlotValue($tableName, $entryIds[$i], $newColumn),
                "Value {$expected} must survive the relocation.",
            );
        }

        // And the vacated column is tombstoned, awaiting the Liberator —
        // the double-occupancy ADR 0033's capacity check must account for.
        $vacated = $this->fetchTombstonedSlotByPageColumn($pageId, $originalColumn);
        self::assertNotNull($vacated, 'The old slot must be tombstoned, not freed immediately.');
    }

    /**
     * The field's declared shape must be untouched. Compaction relies on
     * this: it relocates storage without changing what the field *is*,
     * so a consumer sees no schema change at all.
     */
    public function testSameTypeRetypeLeavesTheFieldShapeUnchanged(): void
    {
        $this->provisionPage(['i_str_01', 'i_str_02']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'code');
        $this->reserveSlotFor($fieldId);
        $this->seedEntry(1, $modelId, ['code' => 'x']);

        $before = $this->fetchFieldRow($fieldId);

        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'string',
            newIsFilterable: null,
        );
        $this->makeRetypeBackfillWorkSource()->tickOne('test-same-type-shape');

        $after = $this->fetchFieldRow($fieldId);

        self::assertSame($before['declared_type'], $after['declared_type']);
        self::assertSame((int) $before['is_filterable'], (int) $after['is_filterable']);
        self::assertSame($before['name'], $after['name']);
        self::assertSame($before['model_id'], $after['model_id']);
    }

    /**
     * Every declared type must survive its own identity diagonal, not
     * just `string` — compaction relocates whatever families a model
     * happens to use, and a family that silently NULLed on relocation
     * would be data loss in the read path's eyes.
     *
     * @dataProvider identityDiagonalCases
     */
    public function testIdentityDiagonalPreservesValuesForEveryFamily(
        string $declaredType,
        string $indexedSlotA,
        string $indexedSlotB,
        mixed $written,
        string $expected,
    ): void {
        $pageId  = $this->provisionPage([$indexedSlotA, $indexedSlotB]);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, $declaredType, true, 'value');
        $this->reserveSlotFor($fieldId);
        $entryId = $this->seedEntry(1, $modelId, ['value' => $written]);

        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: $declaredType,
            newIsFilterable: null,
        );
        $this->makeRetypeBackfillWorkSource()->tickOne('test-identity-' . $declaredType);

        $slot = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($slot);
        self::assertSame('ready', (string) $slot['status']);

        $actual = $this->fetchSlotValue(
            $this->pageTableNameFor($pageId),
            $entryId,
            (string) $slot['slot_column'],
        );

        self::assertNotNull($actual, "A {$declaredType} value must not NULL out on its own identity diagonal.");
        self::assertSame($expected, (string) $actual);
    }

    /** @return iterable<string, array{string, string, string, mixed, string}> */
    public static function identityDiagonalCases(): iterable
    {
        yield 'string'   => ['string', 'i_str_01', 'i_str_02', 'hello', 'hello'];
        yield 'int'      => ['int', 'i_int_01', 'i_int_02', 42, '42'];
        yield 'numeric'  => ['numeric', 'i_num_01', 'i_num_02', 1.5, '1.5'];
        yield 'datetime' => [
            'datetime',
            'i_dt_01',
            'i_dt_02',
            '2026-01-02 03:04:05',
            '2026-01-02 03:04:05',
        ];
    }
}
