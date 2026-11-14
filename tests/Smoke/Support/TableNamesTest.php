<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StarDust\Config\Config;
use StarDust\Support\TableNames;

/**
 * What `TableNames` builds and which prefixes it accepts.
 *
 * DB-free by design. The longest-identifier case is arithmetic and not a
 * server probe.
 */
final class TableNamesTest extends TestCase
{
    public function testNoPrefixGivesTheBareNames(): void
    {
        $names = new TableNames();

        self::assertSame('', $names->prefix);
        self::assertSame('entry_data', $names->entryData);
        self::assertSame('backfill_checkpoints', $names->backfillCheckpoints);
        self::assertSame('stardust_models', $names->models);
        self::assertSame('stardust_slot_assignments', $names->slotAssignments);
        self::assertSame('entry_slots_page_3', $names->page(3));
        self::assertSame('fk_fields_model', $names->fkFieldsModel);
        self::assertSame('watcher', $names->daemon('watcher'));
    }

    public function testAnEmptyPrefixMeansNone(): void
    {
        self::assertSame('entry_data', (new TableNames(''))->entryData);
    }

    public function testAPrefixIsJoinedWithoutASeparator(): void
    {
        $names = new TableNames('sd_');

        self::assertSame('sd_entry_data', $names->entryData);
        self::assertSame('sd_backfill_checkpoints', $names->backfillCheckpoints);
        self::assertSame('sd_stardust_pages', $names->pages);
        self::assertSame('sd_entry_slots_page_3', $names->page(3));
        self::assertSame('sd_fk_slot_assignments_page', $names->fkSlotAssignmentsPage);
        self::assertSame('sd_ck_schema_version_singleton', $names->ckSchemaVersionSingleton);
        self::assertSame('sd_watcher', $names->daemon('watcher'));
    }

    public function testEveryBaseNameIsReachableThroughAProperty(): void
    {
        $bare = new TableNames();
        $values = [];
        foreach ((new ReflectionClass($bare))->getProperties() as $property) {
            if ($property->getName() !== 'prefix') {
                $values[] = $property->getValue($bare);
            }
        }

        foreach (TableNames::BASE_NAMES as $base) {
            if (str_ends_with($base, '_page_')) {
                continue;
            }
            self::assertContains($base, $values, "{$base} has no property on TableNames.");
        }
    }

    public function testPageTableShapeFollowsThePrefix(): void
    {
        $bare = new TableNames();
        $prefixed = new TableNames('sd_');

        self::assertTrue($bare->isPageTable('entry_slots_page_12'));
        self::assertFalse($bare->isPageTable('sd_entry_slots_page_12'));
        self::assertTrue($prefixed->isPageTable('sd_entry_slots_page_12'));
        self::assertFalse($prefixed->isPageTable('entry_slots_page_12'));
        self::assertFalse($prefixed->isPageTable('sd_entry_slots_page_0'));
        self::assertFalse($prefixed->isPageTable('sd_entry_slots_page_1; DROP TABLE x'));
    }

    /** @return array<string, array{string}> */
    public static function rejectedPrefixes(): array
    {
        return [
            'uppercase'        => ['SD_'],
            'starts with digit' => ['1sd_'],
            'starts with underscore' => ['_sd'],
            'space'            => ['s d_'],
            'dash'             => ['sd-'],
            'dot'              => ['a.b_'],
            'quote'            => ["sd'_"],
            'semicolon'        => ['sd;_'],
            'seventeen characters' => [str_repeat('a', 17)],
        ];
    }

    #[DataProvider('rejectedPrefixes')]
    public function testAnInvalidPrefixIsRejected(string $prefix): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TableNames($prefix);
    }

    public function testASixteenCharacterPrefixIsAccepted(): void
    {
        self::assertSame(str_repeat('a', 16) . 'entry_data', (new TableNames(str_repeat('a', 16)))->entryData);
    }

    /**
     * The 16 character cap exists so the longest identifier fits MySQL's
     * 64. That is a page index, `ix_{table}_{slot}`, built from the page
     * table name and the slot name, with a 19 digit page number.
     */
    public function testTheLongestIdentifierFitsTheServerLimit(): void
    {
        $names = new TableNames(str_repeat('a', 16));
        $pageTable = $names->page(PHP_INT_MAX);

        self::assertSame(19, strlen((string) PHP_INT_MAX));
        self::assertLessThanOrEqual(64, strlen('ix_' . $pageTable . '_i_str_25'));
        self::assertLessThanOrEqual(64, strlen('fk_' . $pageTable . '_entry'));
        self::assertSame(64, strlen('ix_' . $pageTable . '_i_str_25'));
    }

    public function testConfigRejectsABadPrefixWhenItIsBuilt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config(pdo: $this->unconnectedPdo(), tablePrefix: 'Bad-Prefix');
    }

    public function testConfigTreatsAnEmptyPrefixAsNone(): void
    {
        self::assertNull((new Config(pdo: $this->unconnectedPdo(), tablePrefix: ''))->tablePrefix);
        self::assertNull((new Config(pdo: $this->unconnectedPdo()))->tablePrefix);
        self::assertSame('sd_', (new Config(pdo: $this->unconnectedPdo(), tablePrefix: 'sd_'))->tablePrefix);
    }

    /** Config only stores the PDO, so a handle that never connects is enough. */
    private function unconnectedPdo(): PDO
    {
        return (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
    }
}
