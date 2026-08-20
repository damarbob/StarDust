<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Write;

use PDO;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Config\Config;
use StarDust\Exception\EntryNotFoundException;
use StarDust\Exception\UncoercibleSlotValueException;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Read\EntryQuery;
use StarDust\StarDust;
use StarDust\Tests\Smoke\ReadPathTestCase;

/**
 * `StarDust::updateEntry()` — full-replace (PUT) update.
 *
 * Driven through the public facade rather than `EntryWriter` directly,
 * because the contract under test is the engine surface a consumer
 * actually calls.
 *
 * The load-bearing case is {@see self::testOmittingAFieldClearsItsSlot()}.
 * Everything else here is ordinary CRUD behaviour; that one encodes why
 * PUT semantics need special handling at all — `PayloadSplitter` only
 * plans writes for fields *present* in the payload, so without an
 * explicit clear an omitted field would keep its indexed value while
 * disappearing from `entry_data.fields`, and a filter would go on
 * matching a value the entry no longer has.
 */
final class EntryUpdateTest extends ReadPathTestCase
{
    private function engine(?\Psr\Log\LoggerInterface $logger = null): StarDust
    {
        return new StarDust(new Config(
            pdo: $this->pdo,
            logger: $logger ?? new NullLogger(),
            clock: new SystemClock(),
        ));
    }

    /**
     * Two filterable string fields on one page.
     *
     * @return array{0: int, 1: string, 2: string}  [modelId, nameField, industryField]
     */
    private function twoFieldModel(int $tenantId = 1): array
    {
        $this->provisionPage(['i_str_01', 'i_str_02']);
        $modelId = $this->createModel($tenantId);
        $this->reserveSlotFor($this->createField($modelId, 'string', true, 'name'));
        $this->reserveSlotFor($this->createField($modelId, 'string', true, 'industry'));

        return [$modelId, 'name', 'industry'];
    }

    /** @return array<string, mixed>|null */
    private function slotRow(int $entryId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM entry_slots_page_1 WHERE entry_id = ?');
        $stmt->execute([$entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function fieldsJson(int $entryId): array
    {
        $stmt = $this->pdo->prepare('SELECT fields FROM entry_data WHERE id = ?');
        $stmt->execute([$entryId]);

        return json_decode((string) $stmt->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
    }

    // ---------------------------------------------------------------
    // The happy path
    // ---------------------------------------------------------------

    public function testUpdateRewritesBothThePayloadAndTheIndexedSlot(): void
    {
        [$modelId] = $this->twoFieldModel();
        $entryId = $this->seedEntry(1, $modelId, ['name' => 'Acme', 'industry' => 'energy']);

        $this->engine()->updateEntry(1, $entryId, ['name' => 'Globex', 'industry' => 'energy']);

        self::assertSame('Globex', $this->fieldsJson($entryId)['name']);
        self::assertSame('Globex', $this->slotRow($entryId)['i_str_01']);
    }

    /** The point of updating an indexed field: filters follow the new value. */
    public function testFiltersMatchTheNewValueAndNotTheOld(): void
    {
        [$modelId] = $this->twoFieldModel();
        $entryId = $this->seedEntry(1, $modelId, ['name' => 'Acme', 'industry' => 'energy']);

        $this->engine()->updateEntry(1, $entryId, ['name' => 'Globex', 'industry' => 'energy']);

        $engine = $this->engine();
        self::assertCount(1, $engine->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            filter: LeafNode::local('name', 'eq', 'Globex'),
        ))->rows);
        self::assertCount(0, $engine->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            filter: LeafNode::local('name', 'eq', 'Acme'),
        ))->rows);
    }

    /**
     * **The PUT invariant.** An omitted field is removed from the JSON
     * payload AND its slot column is cleared, so no stale indexed value
     * can outlive the payload that set it.
     */
    public function testOmittingAFieldClearsItsSlot(): void
    {
        [$modelId] = $this->twoFieldModel();
        $entryId = $this->seedEntry(1, $modelId, ['name' => 'Acme', 'industry' => 'energy']);
        self::assertSame('energy', $this->slotRow($entryId)['i_str_02'], 'fixture sanity');

        $this->engine()->updateEntry(1, $entryId, ['name' => 'Acme']);

        self::assertArrayNotHasKey('industry', $this->fieldsJson($entryId));
        self::assertNull($this->slotRow($entryId)['i_str_02'], 'The omitted field must not keep its indexed value.');

        // And the cleared value must be unreachable through a filter —
        // the failure this invariant actually prevents.
        self::assertCount(0, $this->engine()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            filter: LeafNode::local('industry', 'eq', 'energy'),
        ))->rows);
    }

    /** An explicit null is the caller setting the field, not omitting it — both clear the slot. */
    public function testExplicitNullIsHonouredAndKeptInThePayload(): void
    {
        [$modelId] = $this->twoFieldModel();
        $entryId = $this->seedEntry(1, $modelId, ['name' => 'Acme', 'industry' => 'energy']);

        $this->engine()->updateEntry(1, $entryId, ['name' => 'Acme', 'industry' => null]);

        self::assertArrayHasKey('industry', $this->fieldsJson($entryId));
        self::assertNull($this->fieldsJson($entryId)['industry']);
        self::assertNull($this->slotRow($entryId)['i_str_02']);
    }

    public function testUpdateLeavesModelAndCreatedAtUntouched(): void
    {
        [$modelId] = $this->twoFieldModel();
        $entryId = $this->seedEntry(1, $modelId, ['name' => 'Acme']);

        $before = $this->pdo->query("SELECT model_id, created_at FROM entry_data WHERE id = {$entryId}")
            ->fetch(PDO::FETCH_ASSOC);

        $this->engine()->updateEntry(1, $entryId, ['name' => 'Globex']);

        $after = $this->pdo->query("SELECT model_id, created_at FROM entry_data WHERE id = {$entryId}")
            ->fetch(PDO::FETCH_ASSOC);

        self::assertSame($before['model_id'], $after['model_id'], 'model_id is immutable across an update.');
        self::assertSame($before['created_at'], $after['created_at']);
    }

    // ---------------------------------------------------------------
    // Rejections
    // ---------------------------------------------------------------

    public function testUpdatingASoftDeletedEntryThrows(): void
    {
        [$modelId] = $this->twoFieldModel();
        $entryId = $this->seedEntry(1, $modelId, ['name' => 'Acme']);
        $this->engine()->deleteEntry(1, $entryId);

        $this->expectException(EntryNotFoundException::class);
        $this->engine()->updateEntry(1, $entryId, ['name' => 'Globex']);
    }

    /**
     * Tenant isolation: another tenant's entry is indistinguishable
     * from a missing one, and must not be mutated.
     */
    public function testUpdatingAnotherTenantsEntryThrowsAndMutatesNothing(): void
    {
        [$modelId] = $this->twoFieldModel();
        $entryId = $this->seedEntry(1, $modelId, ['name' => 'Acme']);

        try {
            $this->engine()->updateEntry(2, $entryId, ['name' => 'Globex']);
            self::fail('Expected EntryNotFoundException for a foreign tenant.');
        } catch (EntryNotFoundException) {
            // expected
        }

        self::assertSame('Acme', $this->fieldsJson($entryId)['name']);
        self::assertSame('Acme', $this->slotRow($entryId)['i_str_01']);
    }

    public function testUpdatingAMissingEntryThrows(): void
    {
        $this->twoFieldModel();

        $this->expectException(EntryNotFoundException::class);
        $this->engine()->updateEntry(1, 999_999, ['name' => 'Globex']);
    }

    /**
     * A coercion failure must leave the entry exactly as it was —
     * `PayloadSplitter` runs before `entry_data` is touched, and the
     * transaction covers the rest.
     */
    public function testUncoercibleValueRollsBackTheEntireUpdate(): void
    {
        $this->provisionPage(['i_str_01', 'i_int_01']);
        $modelId = $this->createModel(1);
        $this->reserveSlotFor($this->createField($modelId, 'string', true, 'name'));
        $this->reserveSlotFor($this->createField($modelId, 'int', true, 'employees'));

        $entryId = $this->seedEntry(1, $modelId, ['name' => 'Acme', 'employees' => 340]);

        try {
            $this->engine()->updateEntry(1, $entryId, ['name' => 'Globex', 'employees' => 'not-an-int']);
            self::fail('Expected UncoercibleSlotValueException.');
        } catch (UncoercibleSlotValueException) {
            // expected
        }

        self::assertSame('Acme', $this->fieldsJson($entryId)['name'], 'The failed update must not have partially applied.');
        self::assertSame('Acme', $this->slotRow($entryId)['i_str_01']);
        self::assertSame(340, (int) $this->slotRow($entryId)['i_int_01']);
    }

    // ---------------------------------------------------------------
    // Exhaustion fallback and observability
    // ---------------------------------------------------------------

    /**
     * ADR 0007 applies to updates too: introducing a filterable field
     * with no live slot succeeds and queues for backfill rather than
     * failing the call.
     */
    public function testUpdateIntroducingAnUnmappedFilterableFieldEnqueues(): void
    {
        [$modelId] = $this->twoFieldModel();
        $entryId = $this->seedEntry(1, $modelId, ['name' => 'Acme']);
        // Registered + filterable, but never reserved — no live slot.
        $this->createField($modelId, 'string', true, 'sector');

        $result = $this->engine()->updateEntry(1, $entryId, ['name' => 'Acme', 'sector' => 'public']);

        self::assertTrue($result->enqueuedForBackfill);
        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM stardust_sync_queue WHERE entry_id = {$entryId}")
                ->fetchColumn(),
        );
        // The value is still durable in the payload meanwhile (ADR 0013).
        self::assertSame('public', $this->fieldsJson($entryId)['sector']);
    }

    public function testUpdateEmitsEntryUpdatedOnTheApiSource(): void
    {
        [$modelId] = $this->twoFieldModel();
        $entryId = $this->seedEntry(1, $modelId, ['name' => 'Acme']);

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->engine(new StdoutNdjsonLogger(new SystemClock(), $stream))
            ->updateEntry(1, $entryId, ['name' => 'Globex']);

        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        $event = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('entry_updated', $event['event']);
        self::assertSame('api', $event['source']);
        self::assertSame($entryId, $event['entry_id']);
        self::assertSame($modelId, $event['model_id']);
    }
}
