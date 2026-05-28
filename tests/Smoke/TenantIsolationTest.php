<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use StarDust\Exception\InvalidTenantIdException;
use StarDust\Read\EntryQuery;
use StarDust\Filter\Ast\LeafNode;

final class TenantIsolationTest extends ReadPathTestCase
{
    public function testReadWithZeroTenantThrowsBeforeSql(): void
    {
        [$modelId] = $this->setupFilterableStringField();

        try {
            $this->reader()->read(new EntryQuery(
                tenantId: 0,
                modelId: $modelId,
            ));
            self::fail('Expected InvalidTenantIdException');
        } catch (InvalidTenantIdException $e) {
            self::assertStringContainsString('tenant_id', $e->getMessage());
        }
    }

    public function testReadWithNegativeTenantThrowsBeforeSql(): void
    {
        [$modelId] = $this->setupFilterableStringField();

        try {
            $this->reader()->read(new EntryQuery(
                tenantId: -42,
                modelId: $modelId,
            ));
            self::fail('Expected InvalidTenantIdException');
        } catch (InvalidTenantIdException $e) {
            self::assertStringContainsString('tenant_id', $e->getMessage());
        }
    }

    public function testGetWithZeroTenantThrowsBeforeSql(): void
    {
        try {
            $this->reader()->get(0, 1);
            self::fail('Expected InvalidTenantIdException');
        } catch (InvalidTenantIdException $e) {
            self::assertStringContainsString('tenant_id', $e->getMessage());
        }
    }

    public function testReadDoesNotReturnOtherTenantsRows(): void
    {
        // Provision a single model with a filterable field; seed both
        // tenants into it. The read for tenant 1 must not surface any
        // tenant 2 rows even though they live in the same page table.
        [$modelId, , , $fieldName] = $this->setupFilterableStringField(tenantId: 1);
        // Also create a duplicate model row for tenant 2 so the
        // `(tenant_id, model_id)` query has matching tenant 2 entries.
        $modelIdT2 = $this->createModel(2, 'shared_model_t2');
        // Note: tenant 2 entries are seeded against tenant 2's own
        // model. The filter target is on tenant 1's model.

        $this->seedEntry(1, $modelId, [$fieldName => 'tenant1_a']);
        $this->seedEntry(1, $modelId, [$fieldName => 'tenant1_b']);
        // Tenant 2 entries go in the same physical entry_data table.
        // They share neither model_id nor tenant_id with the query.
        $this->seedEntry(2, $modelIdT2, []);
        $this->seedEntry(2, $modelIdT2, []);

        $page = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
        ));

        self::assertCount(2, $page->rows);
        foreach ($page->rows as $entry) {
            self::assertSame(1, $entry->tenantId);
        }
    }

    public function testGetReturnsNullForCrossTenantId(): void
    {
        [$modelId] = $this->setupFilterableStringField(tenantId: 1);
        $entryId = $this->seedEntry(1, $modelId, ['name' => 'belongs_to_tenant_1']);

        // Same entry id, different tenant — must not leak.
        $entry = $this->reader()->get(2, $entryId);
        self::assertNull($entry);
    }

    public function testFilterValueDoesNotCrossTenantBoundary(): void
    {
        // Two tenants store rows with the same field value;
        // an equality filter must scope to the caller's tenant only.
        [$modelId, , , $fieldName] = $this->setupFilterableStringField(tenantId: 1);
        $modelIdT2 = $this->createModel(2, 'shared_model_t2');
        $fieldIdT2 = $this->createField($modelIdT2, 'string', true, $fieldName);
        $this->reserveSlotFor($fieldIdT2);

        $this->seedEntry(1, $modelId,    [$fieldName => 'collision']);
        $this->seedEntry(2, $modelIdT2,  [$fieldName => 'collision']);
        $this->seedEntry(2, $modelIdT2,  [$fieldName => 'collision']);

        $page = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            filter: LeafNode::local($fieldName, 'eq', 'collision'),
        ));
        self::assertCount(1, $page->rows);
        self::assertSame(1, $page->rows[0]->tenantId);
    }
}
