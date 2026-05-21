<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;
use UnexpectedValueException;

/**
 * Standalone smoke suite for the default NDJSON logger. Verifies the
 * ADR 0020 record-shape contract (required fields, throwable serialisation)
 * without exercising the database — colocated under `Smoke/` because the
 * suite has no `Unit/` directory yet.
 */
final class StdoutNdjsonLoggerTest extends TestCase
{
    /** @return array{0:StdoutNdjsonLogger, 1:resource} */
    private function newLogger(): array
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        return [new StdoutNdjsonLogger(new SystemClock(), $stream), $stream];
    }

    /**
     * @param resource $stream
     * @return list<array<string,mixed>>
     */
    private function readRecords($stream): array
    {
        rewind($stream);
        $raw = (string) stream_get_contents($stream);
        $lines = array_values(array_filter(explode("\n", $raw)));
        $decoded = [];
        foreach ($lines as $line) {
            $value = json_decode($line, true);
            self::assertIsArray($value);
            $decoded[] = $value;
        }
        return $decoded;
    }

    public function testNormalisesThrowableCauseChain(): void
    {
        [$logger, $stream] = $this->newLogger();

        $root = new RuntimeException('root cause');
        $mid  = new LogicException('middle wrap', 0, $root);
        $top  = new UnexpectedValueException('outermost', 0, $mid);

        $logger->error('failure', ['event' => 'test_fail', 'exception' => $top]);

        $records = $this->readRecords($stream);
        self::assertCount(1, $records);
        $decoded = $records[0];

        self::assertIsArray($decoded['exception'] ?? null);
        self::assertSame(UnexpectedValueException::class, $decoded['exception']['class']);
        self::assertSame('outermost', $decoded['exception']['message']);
        self::assertIsArray($decoded['exception']['previous'] ?? null);
        self::assertSame(LogicException::class, $decoded['exception']['previous']['class']);
        self::assertSame('middle wrap', $decoded['exception']['previous']['message']);
        self::assertIsArray($decoded['exception']['previous']['previous'] ?? null);
        self::assertSame(RuntimeException::class, $decoded['exception']['previous']['previous']['class']);
        self::assertSame('root cause', $decoded['exception']['previous']['previous']['message']);
        self::assertArrayNotHasKey('previous', $decoded['exception']['previous']['previous']);
    }

    public function testNormalisesSingleThrowableHasNoPreviousKey(): void
    {
        [$logger, $stream] = $this->newLogger();

        $logger->error('solo', ['event' => 'test_fail', 'exception' => new RuntimeException('only')]);

        $records = $this->readRecords($stream);
        self::assertCount(1, $records);
        self::assertIsArray($records[0]['exception'] ?? null);
        self::assertSame(RuntimeException::class, $records[0]['exception']['class']);
        self::assertSame('only', $records[0]['exception']['message']);
        self::assertArrayNotHasKey('previous', $records[0]['exception']);
    }

    private const UUID_V4_REGEX =
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testAutoFillsCorrelationIdWhenAbsent(): void
    {
        [$logger, $stream] = $this->newLogger();

        $logger->info('hello', ['event' => 'smoke']);

        $records = $this->readRecords($stream);
        self::assertCount(1, $records);
        self::assertMatchesRegularExpression(self::UUID_V4_REGEX, $records[0]['correlation_id'] ?? '');
    }

    public function testAutoFillsCorrelationIdWhenNullExplicit(): void
    {
        [$logger, $stream] = $this->newLogger();

        $logger->info('hello', ['event' => 'smoke', 'correlation_id' => null]);

        $records = $this->readRecords($stream);
        self::assertCount(1, $records);
        self::assertMatchesRegularExpression(self::UUID_V4_REGEX, $records[0]['correlation_id'] ?? '');
    }

    public function testPreservesCallerSuppliedCorrelationId(): void
    {
        [$logger, $stream] = $this->newLogger();

        $logger->info('hello', ['event' => 'smoke', 'correlation_id' => 'caller-abc']);

        $records = $this->readRecords($stream);
        self::assertCount(1, $records);
        self::assertSame('caller-abc', $records[0]['correlation_id'] ?? null);
    }

    public function testEachCallGetsDistinctCorrelationId(): void
    {
        [$logger, $stream] = $this->newLogger();

        $logger->info('hello', ['event' => 'smoke']);
        $logger->info('hello', ['event' => 'smoke']);

        $records = $this->readRecords($stream);
        self::assertCount(2, $records);
        self::assertNotSame($records[0]['correlation_id'], $records[1]['correlation_id']);
    }
}
