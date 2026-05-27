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
        if (in_array('failwrite', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('failwrite');
        }
        stream_wrapper_register('failwrite', FailWriteStreamWrapper::class);
    }

    public static function tearDownAfterClass(): void
    {
        if (in_array('failwrite', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('failwrite');
        }
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

/**
 * PHP stream wrapper that accepts open/mkdir but returns 0 on every
 * stream_write — mimicking an ENOSPC partition. The CsvArtifactStream
 * treats a short write (written !== expected) as disk-full.
 *
 * Registered for the duration of {@see ChroniclerDiskFullTest} and
 * unregistered after, so no other test sees `failwrite://`.
 */
final class FailWriteStreamWrapper
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        // Pretend the open succeeded.
        return true;
    }

    public function stream_write(string $data): int
    {
        // Short write: simulates ENOSPC. CsvArtifactStream / JsonArtifactStream
        // both treat written < expected as disk-full and raise the typed
        // exception.
        return 0;
    }

    public function stream_close(): void
    {
        // no-op
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        // Allow touch() / chmod() / etc. against virtual paths.
        return true;
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        // Pretend mkdir always succeeds — the factory's
        // ensureArtifactDir() probes is_dir() first; we satisfy that
        // through url_stat() below.
        return true;
    }

    /**
     * @return array<string|int,int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        // Report any failwrite:// path as a writable directory so
        // is_dir() / is_writable() checks pass.
        $mode = 0o040777; // S_IFDIR | 0777
        return [
            0 => 0,
            1 => 0,
            2 => $mode,
            3 => 0,
            4 => 0,
            5 => 0,
            6 => 0,
            7 => 0,
            8 => 0,
            9 => 0,
            10 => 0,
            11 => 0,
            12 => 0,
            'dev'     => 0,
            'ino'     => 0,
            'mode'    => $mode,
            'nlink'   => 0,
            'uid'     => 0,
            'gid'     => 0,
            'rdev'    => 0,
            'size'    => 0,
            'atime'   => 0,
            'mtime'   => 0,
            'ctime'   => 0,
            'blksize' => 0,
            'blocks'  => 0,
        ];
    }

    public function unlink(string $path): bool
    {
        // Best-effort delete from the stream's delete() — always succeed.
        return true;
    }
}
