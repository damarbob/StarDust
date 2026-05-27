<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * Per-tenant round-robin claim ordering + FIFO within tenant
 * (chronicler_daemon.md §4 AC#3).
 *
 * A single-tenant burst must not starve other tenants' oldest jobs:
 * the outer ORDER BY uses `MIN(created_at) GROUP BY tenant_id` to
 * give each tenant priority for its OLDEST pending job.
 */
final class ChroniclerClaimOrderingTest extends Phase7TestCase
{
    public function testRoundRobinAcrossTenants(): void
    {
        $modelA = $this->createModel(1, 'tenant1_model');
        $modelB = $this->createModel(2, 'tenant2_model');
        $this->createFieldNamed($modelA, 'k');
        $this->createFieldNamed($modelB, 'k');

        // Tenant 1: three pending jobs (created earliest).
        $jobA1 = $this->seedExportJob(1, $modelA, 'pending', 'csv', createdAt: '2026-05-26 10:00:00');
        $jobA2 = $this->seedExportJob(1, $modelA, 'pending', 'csv', createdAt: '2026-05-26 10:00:01');
        $jobA3 = $this->seedExportJob(1, $modelA, 'pending', 'csv', createdAt: '2026-05-26 10:00:02');

        // Tenant 2: one pending job created BEFORE tenant 1's first job.
        $jobB1 = $this->seedExportJob(2, $modelB, 'pending', 'csv', createdAt: '2026-05-26 09:59:00');

        // First claim should pick tenant 2's older job (round-robin
        // boundary: the tenant whose MIN(created_at) is earliest wins).
        $this->makeChronicler(pageSize: 100)->tick();
        self::assertSame('completed', $this->fetchExportJob($jobB1)['status']);
        self::assertSame('pending', $this->fetchExportJob($jobA1)['status']);

        // Second claim: tenant 2 has no more pending; pick tenant 1's
        // oldest (jobA1).
        $this->makeChronicler(pageSize: 100)->tick();
        self::assertSame('completed', $this->fetchExportJob($jobA1)['status']);
        self::assertSame('pending', $this->fetchExportJob($jobA2)['status']);

        // FIFO within tenant.
        $this->makeChronicler(pageSize: 100)->tick();
        self::assertSame('completed', $this->fetchExportJob($jobA2)['status']);
        $this->makeChronicler(pageSize: 100)->tick();
        self::assertSame('completed', $this->fetchExportJob($jobA3)['status']);
    }

    public function testTenantBurstCannotStarveAnotherTenantsHead(): void
    {
        // Tenant 1 submits a huge backlog AFTER tenant 2's single job;
        // claim order must still surface tenant 2's job first because
        // its MIN(created_at) is earliest.
        $modelA = $this->createModel(1, 'burst');
        $modelB = $this->createModel(2, 'patient');
        $this->createFieldNamed($modelA, 'k');
        $this->createFieldNamed($modelB, 'k');

        $jobB = $this->seedExportJob(2, $modelB, 'pending', 'csv', createdAt: '2026-05-26 10:00:00');
        for ($i = 0; $i < 5; $i++) {
            $this->seedExportJob(1, $modelA, 'pending', 'csv',
                createdAt: sprintf('2026-05-26 10:00:%02d', 5 + $i));
        }

        $this->makeChronicler()->tick();
        self::assertSame('completed', $this->fetchExportJob($jobB)['status']);
    }
}
