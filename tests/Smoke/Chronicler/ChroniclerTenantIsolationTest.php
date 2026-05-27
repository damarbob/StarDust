<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * Architecture Blueprint §1.2 — every paginated read carries a
 * `tenant_id` predicate. Cross-tenant data must never appear in an
 * artifact.
 */
final class ChroniclerTenantIsolationTest extends Phase7TestCase
{
    public function testArtifactContainsOnlyClaimingTenantRows(): void
    {
        $modelA = $this->createModel(1, 'tenant1_only');
        $modelB = $this->createModel(2, 'tenant2_only');
        $this->createFieldNamed($modelA, 'name');
        $this->createFieldNamed($modelB, 'name');

        // Both tenants have rows in entry_data, but their model_ids
        // are distinct AND the pager's WHERE clause is tenant-scoped.
        $this->seedEntryDataBatch(1, $modelA, 3, static fn (int $i) => ['name' => "T1_{$i}"]);
        $this->seedEntryDataBatch(2, $modelB, 5, static fn (int $i) => ['name' => "T2_{$i}"]);

        // Tenant 1 exports only their own data.
        $jobId = $this->seedExportJob(1, $modelA, 'pending', 'csv');
        $this->makeChronicler()->tick();

        $row = $this->fetchExportJob($jobId);
        $rows = $this->readArtifactCsv((string) $row['artifact_path']);
        self::assertCount(3, $rows);
        foreach ($rows as $r) {
            self::assertStringStartsWith('T1_', $r['name']);
        }
    }

    public function testSoftDeletedRowsExcludedFromExport(): void
    {
        $modelId = $this->createModel(1, 'soft_delete');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $entryIds = $this->seedEntryDataBatch(1, $modelId, 5);

        // Soft-delete the middle three rows.
        $stmt = $this->pdo->prepare(
            'UPDATE entry_data SET deleted_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $stmt->execute([$entryIds[1]]);
        $stmt->execute([$entryIds[2]]);
        $stmt->execute([$entryIds[3]]);

        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');
        $this->makeChronicler()->tick();

        $row = $this->fetchExportJob($jobId);
        $rows = $this->readArtifactCsv((string) $row['artifact_path']);
        self::assertCount(2, $rows, 'Soft-deleted rows must be excluded.');
    }
}
