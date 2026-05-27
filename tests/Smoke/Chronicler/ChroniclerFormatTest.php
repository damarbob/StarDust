<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * RFC 4180 CSV quoting + single-document JSON array structure
 * invariants. Tests focused on the artifact stream behaviour rather
 * than the daemon orchestration.
 */
final class ChroniclerFormatTest extends Phase7TestCase
{
    public function testCsvHeaderDerivedFromStardustFields(): void
    {
        // stardust_fields names are emitted in alphabetical order
        // (HeaderResolver invariant); inserting in a different order
        // should not change the header.
        $modelId = $this->createModel(1, 'header_order');
        $this->createFieldNamed($modelId, 'zebra');
        $this->createFieldNamed($modelId, 'alpha');
        $this->createFieldNamed($modelId, 'mango');

        $this->seedEntryDataBatch(1, $modelId, 1, static fn () => [
            'alpha' => 'A', 'mango' => 'M', 'zebra' => 'Z',
        ]);

        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');
        $this->makeChronicler()->tick();

        $row = $this->fetchExportJob($jobId);
        $raw = (string) file_get_contents((string) $row['artifact_path']);
        $headerLine = explode("\r\n", $raw)[0];
        self::assertSame('alpha,mango,zebra', $headerLine);
    }

    public function testCsvQuotesFieldsContainingSeparators(): void
    {
        $modelId = $this->createModel(1, 'csv_quote');
        $this->createFieldNamed($modelId, 'val');

        $this->seedEntryDataBatch(1, $modelId, 4, static fn (int $i) => [
            'val' => match ($i) {
                0 => 'plain',
                1 => 'has,comma',
                2 => 'has "quote"',
                3 => "has\nnewline",
                default => '',
            },
        ]);

        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');
        $this->makeChronicler()->tick();

        $row = $this->fetchExportJob($jobId);
        $raw = (string) file_get_contents((string) $row['artifact_path']);

        self::assertStringContainsString("plain\r\n", $raw);
        self::assertStringContainsString('"has,comma"', $raw);
        self::assertStringContainsString('"has ""quote"""', $raw);
        self::assertStringContainsString("\"has\nnewline\"", $raw);
    }

    public function testCsvEmbeddedNulRowSkipped(): void
    {
        $modelId = $this->createModel(1, 'csv_nul');
        $this->createFieldNamed($modelId, 'val');

        $this->seedEntryDataBatch(1, $modelId, 3, static fn (int $i) => [
            'val' => match ($i) {
                0 => 'ok-before',
                1 => "bad\0value",
                2 => 'ok-after',
            },
        ]);

        $logger = $this->makeRecordingLogger();
        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');
        $this->makeChronicler($logger)->tick();

        $row = $this->fetchExportJob($jobId);
        self::assertSame('completed', $row['status']);
        self::assertSame(1, (int) $row['skip_count']);

        $skipped = $this->recordsWithEvent($logger->records(), 'row_skipped');
        self::assertCount(1, $skipped);
        self::assertSame('format_invalid', $skipped[0]['context']['reason']);

        // The two valid rows still land in the artifact.
        $artifact = $this->readArtifactCsv((string) $row['artifact_path']);
        self::assertCount(2, $artifact);
        self::assertSame('ok-before', $artifact[0]['val']);
        self::assertSame('ok-after', $artifact[1]['val']);
    }

    public function testJsonSingleDocumentArrayHasCorrectStructure(): void
    {
        $modelId = $this->createModel(1, 'json_struct');
        $this->createFieldNamed($modelId, 'name');
        $this->seedEntryDataBatch(1, $modelId, 3, static fn (int $i) => ['name' => "u{$i}"]);

        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'json');
        $this->makeChronicler()->tick();

        $row = $this->fetchExportJob($jobId);
        $raw = (string) file_get_contents((string) $row['artifact_path']);

        // Begins with `[`, ends with `]`, exactly two commas between
        // three objects (no trailing or leading comma).
        self::assertStringStartsWith('[', $raw);
        self::assertStringEndsWith(']', $raw);
        self::assertSame(2, substr_count($raw, ',{'));

        // Round-trips through json_decode.
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(3, $payload);
    }

    public function testJsonEmptyDatasetProducesBracketsOnly(): void
    {
        $modelId = $this->createModel(1, 'json_empty');
        $this->createFieldNamed($modelId, 'col');

        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'json');
        $this->makeChronicler()->tick();

        $row = $this->fetchExportJob($jobId);
        $raw = (string) file_get_contents((string) $row['artifact_path']);
        self::assertSame('[]', $raw);
    }
}
