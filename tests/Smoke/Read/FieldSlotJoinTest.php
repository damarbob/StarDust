<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Read;

use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * A field has at most one row in the join that says whether it is filterable.
 *
 * The driver asks the registry about a field with `stardust_fields` left-joined to
 * the field's slot assignments in the four live-or-leaving statuses, and the
 * schema snapshot loads its descriptors with the same join. The two agree only
 * while the join gives each field one row. A retype tombstones the old slot and
 * reserves a new one, so a second row would exist if the tombstone kept its
 * `field_id`. It does not: the field is released before the status flips, which
 * is also what the partial unique index and the foreign key need. This test
 * follows a field through a retype and fails if that ever changes.
 */
final class FieldSlotJoinTest extends Phase6bTestCase
{
    /**
     * Rows the join returns for each field, by field id.
     *
     * @return array<int, int>
     */
    private function joinRowsPerField(): array
    {
        $rows = $this->pdo->query(
            'SELECT f.id AS field_id, COUNT(*) AS n'
            . ' FROM stardust_fields f'
            . ' LEFT JOIN stardust_slot_assignments a'
            . "   ON a.field_id = f.id AND a.status IN ('assigned','backfilling','ready','tombstoned')"
            . ' GROUP BY f.id'
        )->fetchAll();

        $perField = [];
        foreach ($rows as $row) {
            $perField[(int) $row['field_id']] = (int) $row['n'];
        }

        return $perField;
    }

    public function testARetypedFieldKeepsOneRowAcrossTheTombstoneAndTheBackfill(): void
    {
        $this->provisionPage(['i_str_01', 'i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'value');
        $this->reserveSlotFor($fieldId);
        $this->seedEntry(1, $modelId, ['value' => '42']);
        self::assertSame([$fieldId => 1], $this->joinRowsPerField());

        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );

        // The old slot is tombstoned and the new one backfilling, so two slot
        // rows exist for this field's history and only one reaches the join.
        $tombstoned = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM stardust_slot_assignments WHERE status = 'tombstoned'")
            ->fetchColumn();
        self::assertSame(1, $tombstoned, 'The fixture must leave a tombstoned slot behind.');
        self::assertSame('backfilling', $this->fetchLiveSlotForField($fieldId)['status'] ?? null);
        self::assertSame([$fieldId => 1], $this->joinRowsPerField());

        $this->makeRetypeBackfillWorkSource()->tickOne('field-slot-join');

        self::assertSame('ready', $this->fetchLiveSlotForField($fieldId)['status'] ?? null);
        self::assertSame([$fieldId => 1], $this->joinRowsPerField());
    }
}
