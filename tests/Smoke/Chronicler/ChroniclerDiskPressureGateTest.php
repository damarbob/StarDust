<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Chronicler\DiskPressureGate;
use StarDust\Tests\Smoke\Phase7TestCase;
use StarDust\Tests\Smoke\Support\FailWriteStreamWrapper;

/**
 * DiskPressureGate behaviour (ADR 0051). The gate skips claiming when
 * either the free-space ratio falls below the threshold OR the write
 * probe cannot put `probeBytes` into the artifact directory. In-flight
 * jobs continue regardless — the gate is a pre-claim circuit only.
 *
 * The `failwrite://` wrapper isolates the write probe perfectly:
 * `disk_free_space()` is not stream-wrapper aware, so it returns false
 * for such a path, the ratio probe reads null (no pressure), and any
 * trip must therefore have come from the probe.
 */
final class ChroniclerDiskPressureGateTest extends Phase7TestCase
{
    public static function setUpBeforeClass(): void
    {
        FailWriteStreamWrapper::register();
    }

    public static function tearDownAfterClass(): void
    {
        FailWriteStreamWrapper::unregister();
    }

    protected function tearDown(): void
    {
        FailWriteStreamWrapper::reset();
        parent::tearDown();
    }

    // === Ratio check ===

    public function testLowDiskEmittedAndClaimsSkipped(): void
    {
        $modelId = $this->createModel(1, 'low_disk');
        $this->createFieldNamed($modelId, 'k');
        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');

        $logger = $this->makeRecordingLogger();
        // 1.01 threshold ⇒ always fires (free_pct in [0, 1] can never
        // exceed 1.01, so the ratio check trips unconditionally).
        $this->makeChronicler($logger, lowDiskThresholdPct: 1.01)->tick();

        $low = $this->recordsWithEvent($logger->records(), 'low_disk');
        self::assertCount(1, $low);
        self::assertNull($low[0]['context']['tenant_id']);
        self::assertSame('free_pct', $low[0]['context']['cause']);

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
        // 0.0 threshold + probe disabled ⇒ never fires.
        $this->makeChronicler($logger, lowDiskThresholdPct: 0.0)->tick();

        $low = $this->recordsWithEvent($logger->records(), 'low_disk');
        self::assertCount(0, $low);
    }

    public function testGateProbePreservesValuesForEventPayload(): void
    {
        $dir = $this->makeTempArtifactDir();
        $reading = (new DiskPressureGate($dir, 0.10))->sample();
        // The ratio returns a fraction in [0, 1] OR null when the
        // probe call fails.
        if ($reading->freePct !== null) {
            self::assertGreaterThanOrEqual(0.0, $reading->freePct);
            self::assertLessThanOrEqual(1.0, $reading->freePct);
        }
        self::assertSame($dir, $reading->partition);
        self::assertSame(0.10, $reading->thresholdPct);
    }

    // === Write probe (ADR 0051) ===

    public function testWriteProbeFailureSkipsClaimAndNamesCause(): void
    {
        $modelId = $this->createModel(1, 'probe_fail');
        $this->createFieldNamed($modelId, 'k');
        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');

        $logger = $this->makeRecordingLogger();
        $this->makeChronicler(
            $logger,
            artifactDir: 'failwrite:///disk',
            lowDiskThresholdPct: 0.10,
            diskProbeBytes: 4096,
        )->tick();

        $low = $this->recordsWithEvent($logger->records(), 'low_disk');
        self::assertCount(1, $low);
        $ctx = $low[0]['context'];

        self::assertSame('write_probe', $ctx['cause']);
        self::assertSame('write', $ctx['probe_stage']);
        self::assertSame(4096, $ctx['probe_bytes']);
        self::assertSame('failwrite:///disk', $ctx['partition']);
        // disk_free_space() is not stream-wrapper aware, so the ratio
        // probe is unavailable here — this also covers the null-probe
        // fail-open branch, which nothing exercised before.
        self::assertNull($ctx['free_pct']);

        // The job was never claimed.
        $row = $this->fetchExportJob($jobId);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['worker_identity']);
    }

    public function testRatioTripShortCircuitsTheWriteProbe(): void
    {
        $dir = $this->makeTempArtifactDir();
        $before = scandir($dir);
        self::assertIsArray($before);

        // Ratio trips unconditionally; the probe must not run at all.
        $reading = (new DiskPressureGate($dir, 1.01, 4096))->sample();

        self::assertSame('free_pct', $reading->cause);
        self::assertNull($reading->probeStage);
        self::assertNull($reading->probePath);

        $after = scandir($dir);
        self::assertIsArray($after);
        self::assertSame($before, $after, 'ratio trip must not touch the filesystem');
        self::assertSame([], glob(DiskPressureGate::probeGlob($dir)) ?: []);
    }

    public function testProbeBytesZeroRestoresRatioOnlyFailOpen(): void
    {
        $modelId = $this->createModel(1, 'probe_off');
        $this->createFieldNamed($modelId, 'k');
        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');

        $logger = $this->makeRecordingLogger();
        // Same unwritable directory as the failing-probe test, but the
        // probe is disabled — so the gate must fall through and claim.
        $this->makeChronicler(
            $logger,
            artifactDir: 'failwrite:///disk',
            lowDiskThresholdPct: 0.10,
            diskProbeBytes: 0,
        )->tick();

        self::assertCount(0, $this->recordsWithEvent($logger->records(), 'low_disk'));

        $row = $this->fetchExportJob($jobId);
        self::assertNotSame('pending', $row['status'], 'probe disabled ⇒ the job is claimed');
    }

    public function testSampleNamesTheDirectoryItMeasuredAndCreatesItForTheProbe(): void
    {
        // Regression: an earlier version fell back to
        // sys_get_temp_dir() and probed THAT, while still reporting the
        // configured directory as `partition` — so a low_disk event
        // could name a directory that was never the one measured.
        $missingDir = $this->makeTempArtifactDir() . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'deep';
        self::assertDirectoryDoesNotExist($missingDir);

        $reading = (new DiskPressureGate($missingDir, 0.10, 4096))->sample();

        self::assertSame($missingDir, $reading->partition);
        // With the probe enabled the gate creates the directory, so
        // the probe has somewhere to write.
        self::assertDirectoryExists($missingDir);
        self::assertNull($reading->cause, 'a real, writable directory passes');
    }

    public function testProbeDisabledTakesNoFilesystemSideEffect(): void
    {
        // The `0` opt-out must be complete: no directory creation either.
        $missingDir = $this->makeTempArtifactDir() . DIRECTORY_SEPARATOR . 'untouched';

        $reading = (new DiskPressureGate($missingDir, 0.10, 0))->sample();

        self::assertDirectoryDoesNotExist($missingDir);
        self::assertSame($missingDir, $reading->partition);
        self::assertNull($reading->freePct);
        self::assertFalse($reading->shouldSkipClaim());
    }

    public function testSuccessfulProbeLeavesNoFileBehind(): void
    {
        $dir = $this->makeTempArtifactDir();

        $reading = (new DiskPressureGate($dir, 0.0, 4096))->sample();

        self::assertNull($reading->cause);
        self::assertNotNull($reading->probePath);
        self::assertFileDoesNotExist($reading->probePath);
        self::assertSame([], glob(DiskPressureGate::probeGlob($dir)) ?: []);
    }

    public function testConsecutiveProbesUseDistinctPaths(): void
    {
        // The unique filename is load-bearing for multi-worker safety:
        // with a fixed name, one worker's cleanup unlink would delete
        // another's in-flight probe file.
        $dir  = $this->makeTempArtifactDir();
        $gate = new DiskPressureGate($dir, 0.0, 1024);

        $first  = $gate->sample();
        $second = $gate->sample();

        self::assertNotNull($first->probePath);
        self::assertNotNull($second->probePath);
        self::assertNotSame($first->probePath, $second->probePath);
    }

    public function testMkdirFailureFailsClosed(): void
    {
        // A directory that cannot be created is a probe failure, not a
        // fall-through: the alternative is claiming a job the
        // processor will then die on, outside any try block.
        FailWriteStreamWrapper::$failAt      = 'mkdir';
        FailWriteStreamWrapper::$statMissing = true;

        $reading = (new DiskPressureGate('failwrite:///nope', 0.10, 4096))->sample();

        self::assertSame('write_probe', $reading->cause);
        self::assertSame('mkdir', $reading->probeStage);
        self::assertTrue($reading->shouldSkipClaim());
    }

    public function testOpenFailureFailsClosed(): void
    {
        FailWriteStreamWrapper::$failAt = 'open';

        $reading = (new DiskPressureGate('failwrite:///disk', 0.10, 4096))->sample();

        self::assertSame('write_probe', $reading->cause);
        self::assertSame('open', $reading->probeStage);
    }

    public function testFlushFailureFailsClosed(): void
    {
        FailWriteStreamWrapper::$failAt = 'flush';

        $reading = (new DiskPressureGate('failwrite:///disk', 0.10, 4096))->sample();

        self::assertSame('write_probe', $reading->cause);
        self::assertSame('flush', $reading->probeStage);
    }
}
