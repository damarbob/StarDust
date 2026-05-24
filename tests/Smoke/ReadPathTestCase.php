<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use Psr\Log\NullLogger;
use StarDust\Read\EntryReader;
use StarDust\Write\EntryPayload;
use StarDust\Write\EntryWriter;
use StarDust\Write\SlotRowUpserter;
use StarDust\Clock\SystemClock;

/**
 * Shared scaffolding for Phase 4 read-path smoke tests.
 *
 * Extends {@see WritePathTestCase} so the registry helpers (provision
 * a page, create a model, create a field, reserve a slot) are
 * inherited unchanged. Adds two read-focused helpers:
 *
 *   - {@see self::seedEntry()} writes one entry through the real
 *     Phase 3 {@see EntryWriter}, so the slot/JSON split mirrors
 *     production exactly (no synthetic INSERTs).
 *   - {@see self::reader()} builds an {@see EntryReader} bound to
 *     the test's PDO + a {@see NullLogger}. Individual tests inject
 *     a recording logger when they need to assert log events.
 */
abstract class ReadPathTestCase extends WritePathTestCase
{
    /**
     * Seed one entry via the real write path so per-entry slot UPSERTs
     * exercise the same code production uses.
     */
    protected function seedEntry(int $tenantId, int $modelId, array $fields): int
    {
        $writer = new EntryWriter(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new NullLogger(),
            slotRowUpserter: new SlotRowUpserter($this->pdo),
        );
        $result = $writer->write(new EntryPayload(
            tenantId: $tenantId,
            modelId: $modelId,
            fields: $fields,
        ));
        return $result->entryId;
    }

    /**
     * Provision the standard read-path fixture:
     *   - one page with `i_str_01` filterable
     *   - one model
     *   - one filterable string field reserved on `i_str_01`
     *
     * Returns `[modelId, fieldId, pageId, fieldName]`.
     *
     * @return array{0: int, 1: int, 2: int, 3: string}
     */
    protected function setupFilterableStringField(int $tenantId = 1): array
    {
        $pageId = $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel($tenantId);
        $fieldName = 'name';
        $fieldId = $this->createField($modelId, 'string', true, $fieldName);
        $this->reserveSlotFor($fieldId);
        return [$modelId, $fieldId, $pageId, $fieldName];
    }

    protected function reader(?\Psr\Log\LoggerInterface $logger = null): EntryReader
    {
        return new EntryReader(
            pdo: $this->pdo,
            logger: $logger ?? new NullLogger(),
        );
    }
}
