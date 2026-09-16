<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Chronicler\ArtifactStreamFactory;
use StarDust\Chronicler\ClaimKind;
use StarDust\Chronicler\ClaimedJob;
use StarDust\Chronicler\EntryDataPager;
use StarDust\Chronicler\ExportJobProcessor;
use StarDust\Chronicler\HeaderResolver;
use StarDust\Chronicler\JobOutcome;
use StarDust\Clock\SystemClock;
use StarDust\Exception\ChroniclerArtifactDiskFullException;
use StarDust\Tests\Smoke\Phase7TestCase;
use StarDust\Tests\Smoke\Support\FailWriteStreamWrapper;

/**
 * ADR 0025 commitment: `ENOSPC`/short-write during artifact append
 * yields `failed:disk_full` + `job_failed{reason: 'disk_full'}` (a
 * different event from the pre-claim `low_disk` advisory).
 *
 * Simulated via a custom PHP stream wrapper (`failwrite://`) that
 * accepts `fopen`/`mkdir` but returns 0 on every `stream_write` —
 * which is exactly the short-write condition
 * {@see \StarDust\Chronicler\CsvArtifactStream} treats as disk-full.
 * Pointing the `ArtifactStreamFactory` at `failwrite:///disk` makes
 * the very first byte trip the exception.
 */
final class ChroniclerDiskFullTest extends Phase7TestCase
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
        // The wrapper carries mutable static state; every consumer
        // must reset it or a later test inherits this one's stage.
        FailWriteStreamWrapper::reset();
        parent::tearDown();
    }

    public function testStreamRaisesDiskFullOnShortWrite(): void
    {
        // Direct stream-level check: CsvArtifactStream rejects a
        // short fwrite() by raising ChroniclerArtifactDiskFullException.
        // No processor / DB involvement — proves the contract at the
        // ArtifactStream layer.
        $stream = new \StarDust\Chronicler\CsvArtifactStream(
            'failwrite:///fake/path.csv',
            ['name'],
        );
        // open() emits the header line, which already exercises a
        // write — so it should throw immediately.
        try {
            $stream->open();
            self::fail('Expected ChroniclerArtifactDiskFullException on header write.');
        } catch (ChroniclerArtifactDiskFullException $e) {
            self::assertStringContainsString('failwrite', $e->getMessage());
        }
    }

    public function testProcessorMarksJobFailedDiskFullAndEmitsEvent(): void
    {
        $modelId = $this->createModel(1, 'disk_full');
        $this->createFieldNamed($modelId, 'k');
        $this->seedEntryDataBatch(1, $modelId, 3);

        $jobId = $this->seedExportJob(
            1, $modelId, 'processing', 'csv',
            workerIdentity: 'host:test:diskfull',
            heartbeatAt: $this->utcNowString(),
            claimedAt: $this->utcNowString(),
        );

        $claim = new ClaimedJob(
            id: $jobId,
            tenantId: 1,
            modelId: $modelId,
            format: 'csv',
            filter: ['model_id' => $modelId],
            lastCursor: null,
            workerIdentity: 'host:test:diskfull',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );

        $logger = $this->makeRecordingLogger();
        $processor = new ExportJobProcessor(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: $logger,
            pager: new EntryDataPager($this->pdo),
            headerResolver: new HeaderResolver($this->pdo),
            // The factory builds `<dir>/export_<id>_<uuid>.csv`. With
            // a `failwrite://` directory the eventual fopen() lands on
            // a path under our wrapper.
            streamFactory: new ArtifactStreamFactory('failwrite:///disk'),
            pageSize: 100,
            interChunkDelayMicros: 0,
            deadlockRetryBudget: 3,
            skipCountCap: 1_000,
            artifactSizeCapBytes: 5 * 1024 * 1024 * 1024,
            dbDisconnectBackoffSeconds: [0, 0, 0],
            sleepFn: static fn (int $_micros) => null,
        );

        $outcome = $processor->process($claim, 'corr-diskfull');

        self::assertSame(JobOutcome::FailedDiskFull, $outcome);

        $failed = $this->recordsWithEvent($logger->records(), 'job_failed');
        self::assertCount(1, $failed);
        self::assertSame('disk_full', $failed[0]['context']['reason']);

        $row = $this->fetchExportJob($jobId);
        self::assertSame('failed', $row['status']);
        self::assertSame('disk_full', $row['failed_reason']);
        // Partial artifact path is nulled out by the failure handler —
        // verified indirectly by checking the row was marked failed,
        // because the stream's delete() call is a best-effort op on
        // our virtual failwrite:// path.
        self::assertNull($row['artifact_path']);
    }
}
