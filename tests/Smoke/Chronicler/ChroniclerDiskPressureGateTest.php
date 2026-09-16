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
        $reading = $gate->sample();
        // The probe returns a fraction in [0, 1] OR null when the
        // probe call fails.
        if ($reading->freePct !== null) {
            self::assertGreaterThanOrEqual(0.0, $reading->freePct);
            self::assertLessThanOrEqual(1.0, $reading->freePct);
        }
        self::assertSame($dir, $reading->partition);
        self::assertSame(0.10, $reading->thresholdPct);
    }

    public function testReadingNamesTheConfiguredDirectoryEvenWhenItDoesNotExist(): void
    {
        // Regression: an earlier version of the gate fell back to
        // sys_get_temp_dir() here and probed THAT instead, while still
        // reporting the configured (non-existent) directory as
        // `partition` — so a `low_disk` event could name a directory
        // that was never the one measured. artifactDir is created
        // lazily on first claim (ArtifactStreamFactory), so a
        // never-yet-used directory is the common case, not an edge one.
        $missingDir = $this->makeTempArtifactDir() . DIRECTORY_SEPARATOR . 'not-created-yet';
        self::assertDirectoryDoesNotExist($missingDir);

        $reading = (new DiskPressureGate($missingDir, 0.10))->sample();

        self::assertSame($missingDir, $reading->partition);
        // Fails OPEN: a probe against a missing directory reports no
        // pressure rather than a misleading reading from elsewhere.
        self::assertNull($reading->freePct);
        self::assertFalse($reading->shouldSkipClaim());
    }

    public function testOneSampleProbesTheOsExactlyOnce(): void
    {
        // Regression: an earlier version of the gate exposed
        // shouldSkipClaim() and freePct() as two separate calls, each
        // re-probing the OS — so the value Chronicler::tickRound()
        // logged as `free_pct` could be a different syscall result
        // from the one that actually decided to skip the claim. A
        // single sample() must be internally self-consistent: the
        // free_pct it reports is exactly the one shouldSkipClaim()
        // used, by construction, since both are computed once.
        $dir = $this->makeTempArtifactDir();
        $gate = new DiskPressureGate($dir, 1.01); // always trips
        $reading = $gate->sample();

        self::assertTrue($reading->shouldSkipClaim());
        self::assertNotNull($reading->freePct);
        self::assertLessThan($reading->thresholdPct, $reading->freePct);
    }
}
