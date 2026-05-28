<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use StarDust\Clock\SystemClock;
use StarDust\Exception\FieldNotFilterableException;
use StarDust\Exception\FieldNotIndexedException;
use StarDust\Exception\PageSizeOutOfRangeException;
use StarDust\Exception\UnknownFieldException;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Read\EntryQuery;

/**
 * Phase 8 reshape: `QueryValidator` was collapsed into the
 * {@see \StarDust\Search\PreFlight\PreFlightPipeline}; this test
 * exercises the same Phase 4 acceptance criteria — `field_unknown`,
 * `field_not_filterable`, `field_not_indexed` (now surfaced as
 * `field_not_filterable` since `supportsFilterOn()` returns false for
 * non-`assigned/ready` slots), and `page_size_out_of_range` — through
 * the live {@see \StarDust\Read\EntryReader} façade.
 */
final class QueryValidatorTest extends ReadPathTestCase
{
    public function testFilterOnUnknownFieldIsRejectedBeforeAnySql(): void
    {
        [$modelId] = $this->setupFilterableStringField();

        [$logger, $stream] = $this->newRecordingLogger();
        $reader = $this->reader($logger);

        try {
            $reader->read(new EntryQuery(
                tenantId: 1,
                modelId: $modelId,
                filter: LeafNode::local('not_a_field', 'eq', 'x'),
            ));
            self::fail('Expected UnknownFieldException');
        } catch (UnknownFieldException) {
            // expected
        }

        $records = $this->readLogRecords($stream);
        $rejection = $this->findRecord($records, 'pre_flight_rejected');
        self::assertNotNull($rejection);
        self::assertSame('api', $rejection['source']);
        self::assertSame('field_unknown', $rejection['reason']);
        self::assertSame('not_a_field', $rejection['field_name']);
    }

    public function testFilterOnNonFilterableFieldIsRejected(): void
    {
        // Provision page; create field with is_filterable=false but
        // still reserve a slot (so the rejection reason is "not
        // filterable", not "not indexed").
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'note');
        $this->reserveSlotFor($fieldId);

        [$logger, $stream] = $this->newRecordingLogger();

        try {
            $this->reader($logger)->read(new EntryQuery(
                tenantId: 1,
                modelId: $modelId,
                filter: LeafNode::local('note', 'eq', 'x'),
            ));
            self::fail('Expected FieldNotFilterableException');
        } catch (FieldNotFilterableException) {
            // expected
        }

        $rejection = $this->findRecord($this->readLogRecords($stream), 'pre_flight_rejected');
        self::assertNotNull($rejection);
        self::assertSame('field_not_filterable', $rejection['reason']);
    }

    public function testFilterOnBackfillingSlotIsRejected(): void
    {
        [$modelId, $fieldId, , $fieldName] = $this->setupFilterableStringField();

        // Force the slot into the `backfilling` state to simulate an
        // in-flight retype — ADR 0016 makes this state-machine the
        // route a field can be on a slot while still serving via
        // JSON_EXTRACT only. With Phase 8, the MySQL driver's
        // `supportsFilterOn()` returns false for `backfilling`/
        // `tombstoned` so the pre-flight rejection surfaces as
        // `field_not_filterable` rather than `field_not_indexed`.
        $this->pdo->prepare(
            'UPDATE stardust_slot_assignments SET status = ? WHERE field_id = ?'
        )->execute(['backfilling', $fieldId]);

        $thrown = null;
        try {
            $this->reader()->read(new EntryQuery(
                tenantId: 1,
                modelId: $modelId,
                filter: LeafNode::local($fieldName, 'eq', 'x'),
            ));
            self::fail('Expected pre-flight rejection for backfilling slot');
        } catch (FieldNotFilterableException $e) {
            // expected — MySQL driver's supportsFilterOn() returns false
            // for non-`assigned`/`ready` slot statuses per ADR 0022.
            $thrown = $e;
        } catch (FieldNotIndexedException $e) {
            // Legacy Phase 4 exception type — also acceptable.
            $thrown = $e;
        }
        self::assertNotNull(
            $thrown,
            'pre-flight must reject a filter targeting a backfilling slot'
        );
    }

    public function testPageSizeOutOfRangeIsRejected(): void
    {
        [$modelId] = $this->setupFilterableStringField();

        try {
            $this->reader()->read(new EntryQuery(
                tenantId: 1,
                modelId: $modelId,
                pageSize: 0,
            ));
            self::fail('Expected PageSizeOutOfRangeException for pageSize=0');
        } catch (PageSizeOutOfRangeException $e) {
            self::assertStringContainsString('pageSize', $e->getMessage());
        }

        try {
            $this->reader()->read(new EntryQuery(
                tenantId: 1,
                modelId: $modelId,
                pageSize: 9999,
            ));
            self::fail('Expected PageSizeOutOfRangeException for pageSize=9999');
        } catch (PageSizeOutOfRangeException $e) {
            self::assertStringContainsString('pageSize', $e->getMessage());
        }
    }

    /**
     * @return array{0: StdoutNdjsonLogger, 1: resource}
     */
    private function newRecordingLogger(): array
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        return [new StdoutNdjsonLogger(new SystemClock(), $stream), $stream];
    }

    /**
     * @param resource $stream
     * @return list<array<string, mixed>>
     */
    private function readLogRecords($stream): array
    {
        rewind($stream);
        $raw = (string) stream_get_contents($stream);
        if ($raw === '') {
            return [];
        }
        $records = [];
        foreach (explode("\n", trim($raw)) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }
        return $records;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return array<string, mixed>|null
     */
    private function findRecord(array $records, string $event): ?array
    {
        foreach ($records as $r) {
            if (($r['event'] ?? null) === $event) {
                return $r;
            }
        }
        return null;
    }
}
