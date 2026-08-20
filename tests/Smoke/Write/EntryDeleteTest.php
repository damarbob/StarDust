<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Write;

use PDO;
use Psr\Log\NullLogger;
use StarDust\Chronicler\EntryDataPager;
use StarDust\Clock\SystemClock;
use StarDust\Config\Config;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Read\EntryQuery;
use StarDust\Search\SearchRequest;
use StarDust\StarDust;
use StarDust\Tests\Smoke\ReadPathTestCase;

/**
 * `StarDust::deleteEntry()` — soft delete.
 *
 * The claim worth testing is not that `deleted_at` gets stamped; it is
 * that stamping one column is genuinely sufficient. Every read surface
 * already filters `deleted_at IS NULL` independently — `BoundedFetch`
 * on the read path, `MysqlNativeDriver` on search and point-read,
 * `EntryDataPager` on export — so this class asserts the row vanishes
 * from all four rather than trusting that they agree.
 */
final class EntryDeleteTest extends ReadPathTestCase
{
    private function engine(?\Psr\Log\LoggerInterface $logger = null): StarDust
    {
        return new StarDust(new Config(
            pdo: $this->pdo,
            logger: $logger ?? new NullLogger(),
            clock: new SystemClock(),
        ));
    }

    /** @return array{0: int, 1: int} [modelId, entryId] */
    private function seededEntry(int $tenantId = 1): array
    {
        [$modelId] = [$this->setupFilterableStringField($tenantId)[0]];
        $entryId = $this->seedEntry($tenantId, $modelId, ['name' => 'Acme']);

        return [$modelId, $entryId];
    }

    // ---------------------------------------------------------------
    // The row disappears from every read surface
    // ---------------------------------------------------------------

    public function testDeletedEntryIsGoneFromPointRead(): void
    {
        [, $entryId] = $this->seededEntry();
        $engine = $this->engine();

        self::assertNotNull($engine->get(1, $entryId), 'fixture sanity');
        self::assertTrue($engine->deleteEntry(1, $entryId));
        self::assertNull($engine->get(1, $entryId));
    }

    public function testDeletedEntryIsGoneFromFilteredReadAndSearch(): void
    {
        [$modelId, $entryId] = $this->seededEntry();
        $engine = $this->engine();
        $engine->deleteEntry(1, $entryId);

        self::assertCount(0, $engine->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            filter: LeafNode::local('name', 'eq', 'Acme'),
        ))->rows);

        self::assertCount(0, $engine->search(new SearchRequest(
            tenantId: 1,
            modelId: $modelId,
            filter: LeafNode::local('name', 'eq', 'Acme'),
        ))->rows);
    }

    /** The export path paginates `entry_data` itself, so it needs its own proof. */
    public function testDeletedEntryIsSkippedByExportPagination(): void
    {
        [$modelId, $entryId] = $this->seededEntry();
        $survivor = $this->seedEntry(1, $modelId, ['name' => 'Globex']);

        $this->engine()->deleteEntry(1, $entryId);

        $rows = (new EntryDataPager($this->pdo))->fetchChunk(1, $modelId, 0, 100);
        $ids = array_map(static fn (\StarDust\Chronicler\EntryDataRow $r): int => $r->id, $rows);

        self::assertSame([$survivor], $ids);
    }

    public function testDeletingOneEntryLeavesItsSiblingsReadable(): void
    {
        [$modelId, $entryId] = $this->seededEntry();
        $survivor = $this->seedEntry(1, $modelId, ['name' => 'Globex']);

        $this->engine()->deleteEntry(1, $entryId);

        self::assertNotNull($this->engine()->get(1, $survivor));
        self::assertCount(1, $this->engine()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
        ))->rows);
    }

    // ---------------------------------------------------------------
    // Idempotency and isolation
    // ---------------------------------------------------------------

    public function testSecondDeleteReturnsFalseAndKeepsTheOriginalTimestamp(): void
    {
        [, $entryId] = $this->seededEntry();
        $engine = $this->engine();

        self::assertTrue($engine->deleteEntry(1, $entryId));

        $stamp = $this->pdo->query("SELECT deleted_at FROM entry_data WHERE id = {$entryId}")->fetchColumn();

        self::assertFalse($engine->deleteEntry(1, $entryId), 'A repeat delete is a no-op, not an error.');
        self::assertSame(
            $stamp,
            $this->pdo->query("SELECT deleted_at FROM entry_data WHERE id = {$entryId}")->fetchColumn(),
            'The second call must not overwrite the original deletion time.',
        );
    }

    public function testDeletingAnotherTenantsEntryReturnsFalseAndLeavesItAlone(): void
    {
        [, $entryId] = $this->seededEntry();

        self::assertFalse($this->engine()->deleteEntry(2, $entryId));
        self::assertNotNull($this->engine()->get(1, $entryId), 'The owning tenant must still see it.');
    }

    public function testDeletingAMissingEntryReturnsFalse(): void
    {
        $this->setupFilterableStringField();

        self::assertFalse($this->engine()->deleteEntry(1, 999_999));
    }

    // ---------------------------------------------------------------
    // Slot retention and observability
    // ---------------------------------------------------------------

    /**
     * Slot columns are deliberately left populated — unreachable
     * without a live `entry_data` row, so clearing them would be a
     * write for no observable difference. Pinned so a future change
     * that starts nulling them is a deliberate decision, not a drift.
     */
    public function testSlotValuesAreRetainedAfterDelete(): void
    {
        [, $entryId] = $this->seededEntry();

        $this->engine()->deleteEntry(1, $entryId);

        $stmt = $this->pdo->prepare('SELECT i_str_01 FROM entry_slots_page_1 WHERE entry_id = ?');
        $stmt->execute([$entryId]);

        self::assertSame('Acme', $stmt->fetchColumn());
    }

    public function testDeleteEmitsEntryDeletedOnlyOnAnActualTransition(): void
    {
        [, $entryId] = $this->seededEntry();

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $engine = $this->engine(new StdoutNdjsonLogger(new SystemClock(), $stream));

        $engine->deleteEntry(1, $entryId);
        $engine->deleteEntry(1, $entryId); // no-op — must emit nothing

        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));

        self::assertCount(1, $lines, 'The no-op second delete must not emit an event.');

        $event = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('entry_deleted', $event['event']);
        self::assertSame('api', $event['source']);
        self::assertSame($entryId, $event['entry_id']);
    }
}
