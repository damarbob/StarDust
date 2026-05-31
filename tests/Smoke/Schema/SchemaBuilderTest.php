<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Schema;

use InvalidArgumentException;
use PDO;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Schema\FieldDefinition;
use StarDust\Schema\SchemaBuilder;
use StarDust\Tests\Smoke\WritePathTestCase;

/**
 * Smoke coverage for the {@see SchemaBuilder} stopgap helper.
 *
 * Verifies the registry-row creation, the get-or-create idempotency
 * contract, the single `stardust_schema_version` bump per inserting
 * call, and the construction-time validation.
 */
final class SchemaBuilderTest extends WritePathTestCase
{
    private function builder(): SchemaBuilder
    {
        return new SchemaBuilder(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new NullLogger(),
        );
    }

    private function schemaVersion(): int
    {
        return (int) $this->pdo
            ->query('SELECT version FROM stardust_schema_version WHERE id = 1')
            ->fetchColumn();
    }

    public function testCreateModelInsertsModelAndFieldsAndBumpsVersionOnce(): void
    {
        $before = $this->schemaVersion();

        $def = $this->builder()->createModel(1, 'company', [
            new FieldDefinition('name', 'string', isFilterable: true),
            new FieldDefinition('employees', 'int', isFilterable: true),
            new FieldDefinition('notes', 'string'),
        ]);

        self::assertGreaterThan(0, $def->modelId);
        self::assertCount(3, $def->fieldIds);
        self::assertSame($def->fieldIds['name'], $def->fieldId('name'));

        // One model + three fields all committed in a single transaction
        // that bumps the schema version exactly once.
        self::assertSame($before + 1, $this->schemaVersion());

        $modelRow = $this->pdo->query(
            'SELECT tenant_id, name FROM stardust_models WHERE id = ' . $def->modelId
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('1', (string) $modelRow['tenant_id']);
        self::assertSame('company', $modelRow['name']);

        $employees = $this->pdo->query(
            'SELECT declared_type, is_filterable FROM stardust_fields WHERE id = ' . $def->fieldId('employees')
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('int', $employees['declared_type']);
        self::assertSame(1, (int) $employees['is_filterable']);
    }

    public function testCreateModelIsIdempotentAndDoesNotBumpVersionOnReRun(): void
    {
        $first = $this->builder()->createModel(1, 'company', [
            new FieldDefinition('name', 'string', isFilterable: true),
        ]);

        $afterFirst = $this->schemaVersion();

        $second = $this->builder()->createModel(1, 'company', [
            new FieldDefinition('name', 'string', isFilterable: true),
        ]);

        // Same ids returned; no duplicate rows; no extra version bump.
        self::assertSame($first->modelId, $second->modelId);
        self::assertSame($first->fieldId('name'), $second->fieldId('name'));
        self::assertSame($afterFirst, $this->schemaVersion());

        $modelCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM stardust_models WHERE tenant_id = 1 AND name = 'company'"
        )->fetchColumn();
        self::assertSame(1, $modelCount);

        $fieldCount = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM stardust_fields WHERE model_id = ' . $first->modelId
        )->fetchColumn();
        self::assertSame(1, $fieldCount);
    }

    public function testDefineFieldAddsToExistingModelAndBumpsVersion(): void
    {
        $modelId = $this->builder()->defineModel(7, 'invoice');
        $before  = $this->schemaVersion();

        $fieldId = $this->builder()->defineField($modelId, 'amount', 'numeric', isFilterable: true);

        self::assertGreaterThan(0, $fieldId);
        self::assertSame($before + 1, $this->schemaVersion());

        // Re-defining the same field is a no-op that returns the same id.
        $again = $this->builder()->defineField($modelId, 'amount', 'numeric', isFilterable: true);
        self::assertSame($fieldId, $again);
        self::assertSame($before + 1, $this->schemaVersion());
    }

    public function testRejectsUnknownDeclaredType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->createModel(1, 'company', [
            new FieldDefinition('weird', 'blob'),
        ]);
    }

    public function testRejectsNonPositiveTenant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->createModel(0, 'company');
    }

    public function testRejectsEmptyModelName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder()->createModel(1, '   ');
    }
}
