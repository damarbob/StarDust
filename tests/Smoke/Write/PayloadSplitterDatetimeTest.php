<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Write;

use StarDust\Exception\UncoercibleSlotValueException;
use StarDust\Tests\Smoke\WritePathTestCase;
use StarDust\Write\EntryPayload;

/**
 * `PayloadSplitter::coerceDatetime()` accepts exactly two unambiguous
 * string shapes — naive `Y-m-d H:i:s` (assumed UTC) and RFC 3339 with
 * an explicit offset — and rejects everything else outright rather
 * than handing it to `new DateTimeImmutable()`, which accepts far more
 * than the two documented shapes and does not always mean what the
 * caller intended.
 *
 * The case this closes: a slash-separated day-first date
 * (`05/01/2026`, the default format in most non-US locales) used to be
 * silently reinterpreted as month-first whenever the day was ≤ 12 (5
 * January read back as 1 May) and rejected only once the day exceeded
 * 12 — an inconsistency that reads as "sorting is scrambled" rather
 * than as an obvious failure, since most rows land close to right and
 * a few land months away.
 */
final class PayloadSplitterDatetimeTest extends WritePathTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function fetchPayload(int $entryId): array
    {
        $stmt = $this->pdo->prepare('SELECT fields FROM entry_data WHERE id = ?');
        $stmt->execute([$entryId]);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $stmt->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSlotRow(int $pageId, int $entryId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM entry_slots_page_{$pageId} WHERE entry_id = ?");
        $stmt->execute([$entryId]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    public function testDayFirstSlashDateIsRejectedRatherThanSilentlyReinterpreted(): void
    {
        [$modelId, , , $fieldName] = $this->setupModelWithReservedField(1, 'datetime');

        // 5 January, written the way most non-US locales format a date.
        // `new DateTimeImmutable()` would have silently read this as
        // 5 May (American m/d/y is assumed for any slash-separated
        // date), which is exactly the kind of misordering that looks
        // like "sorting is broken" rather than an obvious failure.
        $this->expectException(UncoercibleSlotValueException::class);
        $this->expectExceptionMessage("cannot coerce '05/01/2026 00:00:00' to datetime");

        $this->makeEntryWriter()->write(new EntryPayload(
            tenantId: 1,
            modelId: $modelId,
            fields: [$fieldName => '05/01/2026 00:00:00'],
        ));
    }

    public function testDayFirstSlashDateBeyondTheThirteenthAlsoStillRejects(): void
    {
        // Unambiguous under the old behaviour only because day > 12 made
        // `new DateTimeImmutable()` throw — pinned so a future change
        // cannot "fix" this case while leaving day <= 12 silently wrong.
        [$modelId, , , $fieldName] = $this->setupModelWithReservedField(1, 'datetime');

        $this->expectException(UncoercibleSlotValueException::class);

        $this->makeEntryWriter()->write(new EntryPayload(
            tenantId: 1,
            modelId: $modelId,
            fields: [$fieldName => '20/11/2026 00:00:00'],
        ));
    }

    public function testNaiveYmdHisIsAcceptedAndTreatedAsAlreadyUtc(): void
    {
        [$modelId, , $pageId, $fieldName] = $this->setupModelWithReservedField(1, 'datetime');

        $result = $this->makeEntryWriter()->write(new EntryPayload(
            tenantId: 1,
            modelId: $modelId,
            fields: [$fieldName => '2026-01-05 00:00:00'],
        ));

        $row = $this->fetchSlotRow($pageId, $result->entryId);
        self::assertSame('2026-01-05 00:00:00', $row['i_dt_01']);
    }

    public function testRfc3339WithOffsetIsAcceptedAndConvertedToUtc(): void
    {
        [$modelId, , $pageId, $fieldName] = $this->setupModelWithReservedField(1, 'datetime');

        $result = $this->makeEntryWriter()->write(new EntryPayload(
            tenantId: 1,
            modelId: $modelId,
            fields: [$fieldName => '2026-01-05T10:00:00+07:00'],
        ));

        $row = $this->fetchSlotRow($pageId, $result->entryId);
        self::assertSame('2026-01-05 03:00:00', $row['i_dt_01']);
    }

    public function testTheOriginalStringSurvivesVerbatimInTheJsonPayload(): void
    {
        // ADR 0013: entry_data.fields is the system of record and is
        // never rewritten by the slot coercion — only the rejection
        // happens earlier, before any row is touched.
        [$modelId, , , $fieldName] = $this->setupModelWithReservedField(1, 'datetime');

        $result = $this->makeEntryWriter()->write(new EntryPayload(
            tenantId: 1,
            modelId: $modelId,
            fields: [$fieldName => '2026-01-05T10:00:00+07:00'],
        ));

        self::assertSame('2026-01-05T10:00:00+07:00', $this->fetchPayload($result->entryId)[$fieldName]);
    }
}
