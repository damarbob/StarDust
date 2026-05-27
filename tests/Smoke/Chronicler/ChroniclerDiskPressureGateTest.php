<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Chronicler\DiskPressureGate;
use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * DiskPressureGate behaviour. The gate fires when `free_pct <
 * threshold`; a Chronicler tick under disk pressure emits `low_disk`
 * and skips claiming new jobs, but in-flight jobs continue (the cap is
 * a pre-claim circuit only).
 */
final class ChroniclerDiskPressureGateTest extends Phase7TestCase
{
    public function testLowDiskEmittedAndClaimsSkipped(): void
    {
        $modelId = $this->createModel(1, 'low_disk');
        $this->createFieldNamed($modelId, 'k');
        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');

        $logger = $this->makeRecordingLogger();
        // 1.01 threshold ⇒ always fires (free_pct in [0, 1] can never
        // exceed 1.01, so shouldSkipClaim() returns true unconditionally).
        $this->makeChronicler($logger, lowDiskThresholdPct: 1.01)->tick();

        $low = $this->recordsWithEvent($logger->records(), 'low_disk');
        self::assertCount(1, $low);
        self::assertNull($low[0]['context']['tenant_id']);

        // Pending row remains unclaimed.
        $row = $this->fetchExportJob($jobId);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['worker_identity']);
    }

    public function testNoPressureNoLowDiskEvent(): void
    {
        $modelId = $this->createModel(1, 'no_pressure');
        $this->createFieldNamed($modelId, 'k');
        $this->seedExportJob(1, $modelId, 'pending', 'csv');

        $logger = $this->makeRecordingLogger();
        // 0.0 threshold ⇒ never fires.
        $this->makeChronicler($logger, lowDiskThresholdPct: 0.0)->tick();

        $low = $this->recordsWithEvent($logger->records(), 'low_disk');
        self::assertCount(0, $low);
    }

    public function testGateProbePreservesValuesForEventPayload(): void
    {
        $dir = $this->makeTempArtifactDir();
        $gate = new DiskPressureGate($dir, 0.10);
        // The probe returns a fraction in [0, 1] OR null when the
        // probe call fails.
        $pct = $gate->freePct();
        if ($pct !== null) {
            self::assertGreaterThanOrEqual(0.0, $pct);
            self::assertLessThanOrEqual(1.0, $pct);
        }
        self::assertSame($dir, $gate->partition());
        self::assertSame(0.10, $gate->thresholdPct());
    }
}
