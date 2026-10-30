<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Write;

use PDO;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Config\Config;
use StarDust\StarDust;
use StarDust\Tests\Smoke\ReadPathTestCase;

/**
 * The write path against a key page, where each string slot has a generated
 * key beside it.
 *
 * `SlotRowUpserter` never names a key column, so the server fills it on every
 * insert and update. These tests prove it does that for the writes the engine
 * really makes: a first write, a full-replace update, an update that clears a
 * field, and the long values that used to wedge a row on a prefix page.
 *
 * What the key holds is checked through {@see self::entryIdsFoundByKey()},
 * the lookup the key exists to serve, rather than by recomputing it here.
 */
final class StringKeyWriteTest extends ReadPathTestCase
{
    private function engine(): StarDust
    {
        return new StarDust(new Config(pdo: $this->pdo, logger: new NullLogger(), clock: new SystemClock()));
    }

    /**
     * Two filterable string fields on one key page.
     *
     * @return array{0: int, 1: int}  [modelId, pageId]
     */
    private function twoFieldModel(): array
    {
        $pageId = $this->provisionPage(['i_str_01', 'i_str_02']);
        $modelId = $this->createModel(1);
        $this->reserveSlotFor($this->createField($modelId, 'string', true, 'name'));
        $this->reserveSlotFor($this->createField($modelId, 'string', true, 'industry'));

        return [$modelId, $pageId];
    }

    /** @return array<string, mixed> */
    private function slotRow(int $pageId, int $entryId): array
    {
        $stmt = $this->pdo->prepare("SELECT i_str_01, i_str_02, LENGTH(i_str_01_k) AS k1, LENGTH(i_str_02_k) AS k2 FROM entry_slots_page_{$pageId} WHERE entry_id = ?");
        $stmt->execute([$entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    public function testTheKeyIsStoredWhenTheSlotHoldsAValueAndNullWhenItDoesNot(): void
    {
        [$modelId, $pageId] = $this->twoFieldModel();

        $entryId = $this->seedEntry(1, $modelId, ['name' => 'Acme']);

        $row = $this->slotRow($pageId, $entryId);
        self::assertSame('Acme', $row['i_str_01']);
        self::assertSame(48, (int) $row['k1'], 'The key is its full width once the slot holds a value.');
        self::assertNull($row['i_str_02']);
        self::assertNull($row['k2'], 'The key is NULL exactly when the slot is.');
    }

    public function testAnEmptyStringGetsAKeyOfItsOwn(): void
    {
        [$modelId, $pageId] = $this->twoFieldModel();

        $entryId = $this->seedEntry(1, $modelId, ['name' => '']);

        $row = $this->slotRow($pageId, $entryId);
        self::assertSame('', $row['i_str_01']);
        self::assertSame(48, (int) $row['k1']);
        self::assertSame([$entryId], $this->entryIdsFoundByKey($pageId, 'i_str_01', ''));
    }

    public function testValuesTheCollationCallsEqualShareOneKey(): void
    {
        [$modelId, $pageId] = $this->twoFieldModel();

        $paid = $this->seedEntry(1, $modelId, ['name' => 'paid']);
        $upper = $this->seedEntry(1, $modelId, ['name' => 'Paid']);
        $accent = $this->seedEntry(1, $modelId, ['name' => 'païd']);
        $other = $this->seedEntry(1, $modelId, ['name' => 'pad']);

        self::assertSame([$paid, $upper, $accent], $this->entryIdsFoundByKey($pageId, 'i_str_01', 'PAID'));
        self::assertSame([$other], $this->entryIdsFoundByKey($pageId, 'i_str_01', 'pad'));
    }

    public function testAValueOfTheFullFilterBoundGetsAKeyThatSeparatesItFromItsNeighbour(): void
    {
        [$modelId, $pageId] = $this->twoFieldModel();

        $a = str_repeat('a', 4095) . 'b';
        $b = str_repeat('a', 4095) . 'c';
        $first = $this->seedEntry(1, $modelId, ['name' => $a]);
        $second = $this->seedEntry(1, $modelId, ['name' => $b]);

        self::assertSame([$first], $this->entryIdsFoundByKey($pageId, 'i_str_01', $a));
        self::assertSame([$second], $this->entryIdsFoundByKey($pageId, 'i_str_01', $b));
    }

    public function testTheKeyFollowsAFullReplaceUpdate(): void
    {
        [$modelId, $pageId] = $this->twoFieldModel();
        $entryId = $this->seedEntry(1, $modelId, ['name' => 'Acme', 'industry' => 'energy']);

        $this->engine()->updateEntry(1, $entryId, ['name' => 'Globex', 'industry' => 'energy']);

        self::assertSame([$entryId], $this->entryIdsFoundByKey($pageId, 'i_str_01', 'Globex'));
        self::assertSame([], $this->entryIdsFoundByKey($pageId, 'i_str_01', 'Acme'), 'The old value must not stay findable.');
        self::assertSame([$entryId], $this->entryIdsFoundByKey($pageId, 'i_str_02', 'energy'));
    }

    public function testOmittingAFieldClearsItsKeyAsWellAsItsSlot(): void
    {
        [$modelId, $pageId] = $this->twoFieldModel();
        $entryId = $this->seedEntry(1, $modelId, ['name' => 'Acme', 'industry' => 'energy']);

        $this->engine()->updateEntry(1, $entryId, ['name' => 'Acme']);

        $row = $this->slotRow($pageId, $entryId);
        self::assertNull($row['i_str_02']);
        self::assertNull($row['k2'], 'A cleared slot must not leave its old key behind.');
        self::assertSame([], $this->entryIdsFoundByKey($pageId, 'i_str_02', 'energy'));
        self::assertSame([$entryId], $this->entryIdsFoundByKey($pageId, 'i_str_01', 'Acme'));
    }

    /**
     * The failure the key exists to remove. On a prefix page every undo record
     * carries up to 766 characters of each indexed value stored off the row's
     * page, so a row with a handful of long four-byte values could be written
     * and then neither updated nor deleted (errno 1713). Twelve values of 800
     * four-byte characters is well past the point where a prefix page failed
     * on the 16 KB pages these tests run on.
     */
    public function testARowWithManyLongFourByteValuesCanStillBeUpdatedAndDeleted(): void
    {
        $slots = [];
        for ($i = 1; $i <= 12; $i++) {
            $slots[] = sprintf('i_str_%02d', $i);
        }
        $pageId = $this->provisionPage($slots);
        $modelId = $this->createModel(1);
        $fields = [];
        foreach ($slots as $i => $_) {
            $name = sprintf('field_%02d', $i + 1);
            $this->reserveSlotFor($this->createField($modelId, 'string', true, $name));
            $fields[$name] = str_repeat("\u{1F600}", 800);
        }

        $entryId = $this->seedEntry(1, $modelId, $fields);

        $fields['field_01'] = str_repeat("\u{1F600}", 799) . 'x';
        $this->engine()->updateEntry(1, $entryId, $fields);
        self::assertSame([$entryId], $this->entryIdsFoundByKey($pageId, 'i_str_01', $fields['field_01']));

        // The cascade a model purge relies on, which writes an undo record
        // for the page row and every indexed value in it.
        $this->pdo->exec("DELETE FROM entry_data WHERE id = {$entryId}");
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM entry_slots_page_{$pageId} WHERE entry_id = {$entryId}")->fetchColumn(),
        );
    }
}
