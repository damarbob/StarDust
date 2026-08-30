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

    /**
     * Provision one model carrying a filterable, slot-backed field of
     * every slot family, so a sort test can cover all four without
     * rebuilding the fixture per type.
     *
     * All four slots land on a single page, which matters: it is the
     * shape in which a sort can reuse a page the filter already joined,
     * and the one the compiler's alias-reuse branch is written for.
     *
     * Returns `[modelId, ['string' => name, 'int' => name, …]]`.
     *
     * @return array{0: int, 1: array<string, string>}
     */
    protected function setupSortableModel(int $tenantId = 1): array
    {
        $this->provisionPage(['i_str_01', 'i_int_01', 'i_num_01', 'i_dt_01']);
        $modelId = $this->createModel($tenantId);

        $names = [
            'string'   => 'title',
            'int'      => 'rank',
            'numeric'  => 'price',
            'datetime' => 'due_at',
        ];
        foreach ($names as $declaredType => $name) {
            $this->reserveSlotFor($this->createField($modelId, $declaredType, true, $name));
        }

        return [$modelId, $names];
    }

    protected function reader(?\Psr\Log\LoggerInterface $logger = null): EntryReader
    {
        return new EntryReader(
            pdo: $this->pdo,
            logger: $logger ?? new NullLogger(),
        );
    }
}
