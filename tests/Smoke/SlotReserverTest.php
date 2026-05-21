<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use StarDust\Bootstrap\Bootstrapper;
use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Page\PageProvisioner;
use StarDust\Slot\SlotReserver;

/**
 * Phase 2 slot reserver smoke suite.
 *
 * Verifies the `free → assigned` transition is atomic, single-row, type-
 * correct, version-bumping, log-emitting, and gracefully returns null when
 * no free slot of the requested family is available.
 */
final class SlotReserverTest extends TestCase
{
    private const PHASE_1_TABLES = [
        'stardust_slot_assignments',
        'stardust_pages',
        'stardust_fields',
        'stardust_models',
        'stardust_sync_queue',
        'entry_data',
        'stardust_schema_version',
        'stardust_export_jobs',
        'stardust_import_jobs',
        'stardust_reconciler_dlq',
        'backfill_checkpoints',
    ];

    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn  = getenv('STARDUST_TEST_DSN') ?: '';
        $user = getenv('STARDUST_TEST_USER') ?: '';
        $pass = getenv('STARDUST_TEST_PASS') ?: '';

        if ($dsn === '' || $user === '') {
            self::markTestSkipped('STARDUST_TEST_DSN and STARDUST_TEST_USER must be set for smoke tests.');
        }

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            self::fail('Could not connect to test database: ' . $e->getMessage());
        }

        $this->dropEverything();
        (new Bootstrapper($this->pdo))->run();
    }

    protected function tearDown(): void
    {
        if (! isset($this->pdo)) {
            return;
        }
        try {
            $this->dropEverything();
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function dropEverything(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $pages = $this->pdo
            ->query(
                "SELECT table_name FROM information_schema.TABLES"
                . " WHERE table_schema = DATABASE() AND table_name LIKE 'entry_slots_page_%'"
            )
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($pages as $pageTable) {
            try {
                $this->pdo->exec("DROP TABLE IF EXISTS {$pageTable}");
            } catch (\Throwable) {
                // ignored
            }
        }

        foreach (self::PHASE_1_TABLES as $t) {
            try {
                $this->pdo->exec("DROP TABLE IF EXISTS {$t}");
            } catch (\Throwable) {
                // ignored
            }
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function newProvisioner(): PageProvisioner
    {
        return new PageProvisioner(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new NullLogger(),
            provisionerIdentity: 'phpunit/0',
        );
    }

    private function newReserver(?StdoutNdjsonLogger $logger = null): SlotReserver
    {
        return new SlotReserver(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: $logger ?? new NullLogger(),
        );
    }

    private function registerField(string $declaredType, bool $isFilterable = false): int
    {
        $this->pdo->exec(
            "INSERT INTO stardust_models (tenant_id, name, created_at)"
            . " VALUES (1, 'model_" . bin2hex(random_bytes(4)) . "', UTC_TIMESTAMP())"
        );
        $modelId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_fields (model_id, name, declared_type, is_filterable, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $modelId,
            'field_' . bin2hex(random_bytes(4)),
            $declaredType,
            $isFilterable ? 1 : 0,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Exactly one slot transitions free → assigned; 59 remain free; field_id is set. */
    public function testReserveTransitionsExactlyOneSlot(): void
    {
        $this->newProvisioner()->provision();
        $fieldId = $this->registerField('string');

        $assignment = $this->newReserver()->reserve($fieldId);

        self::assertNotNull($assignment);
        self::assertSame('str', $assignment->slotType);

        $statusCounts = $this->pdo
            ->query(
                'SELECT status, COUNT(*) AS n'
                . ' FROM stardust_slot_assignments'
                . ' GROUP BY status'
            )
            ->fetchAll();
        $byStatus = [];
        foreach ($statusCounts as $row) {
            $byStatus[(string) $row['status']] = (int) $row['n'];
        }
        self::assertSame(1, $byStatus['assigned'] ?? 0);
        self::assertSame(59, $byStatus['free'] ?? 0);

        $rowFieldId = (int) $this->pdo
            ->query(
                'SELECT field_id FROM stardust_slot_assignments'
                . " WHERE id = {$assignment->slotAssignmentId}"
            )
            ->fetchColumn();
        self::assertSame($fieldId, $rowFieldId);
    }

    /**
     * Each declared_type maps to the correct slot_type family.
     *
     * @dataProvider provideTypeMappings
     */
    public function testReserveMapsDeclaredTypeToCorrectSlotType(string $declaredType, string $expectedSlotType): void
    {
        $this->newProvisioner()->provision();
        $fieldId = $this->registerField($declaredType);

        $assignment = $this->newReserver()->reserve($fieldId);

        self::assertNotNull($assignment);
        self::assertSame($expectedSlotType, $assignment->slotType);
        self::assertStringStartsWith("i_{$expectedSlotType}_", $assignment->slotColumn);
    }

    /** @return array<string, array{string, string}> */
    public static function provideTypeMappings(): array
    {
        return [
            'string'   => ['string', 'str'],
            'int'      => ['int', 'int'],
            'numeric'  => ['numeric', 'num'],
            'datetime' => ['datetime', 'dt'],
        ];
    }

    /** When no free slot of the requested family exists, reserve() returns null without throwing. */
    public function testReserveReturnsNullWhenNoFreeSlotOfType(): void
    {
        $pageId = $this->newProvisioner()->provision();
        $fieldId = $this->registerField('string');

        // Tombstone every str slot so the family has no free inventory.
        // `tombstoned` rows have field_id NULL, which keeps the partial
        // unique index `ux_slot_assignments_field_live` happy.
        $this->pdo->exec(
            "UPDATE stardust_slot_assignments"
            . " SET status = 'tombstoned', tombstoned_at = UTC_TIMESTAMP()"
            . " WHERE page_id = {$pageId} AND slot_type = 'str'"
        );

        $versionBefore = (int) $this->pdo
            ->query('SELECT version FROM stardust_schema_version WHERE id = 1')
            ->fetchColumn();

        $assignment = $this->newReserver()->reserve($fieldId);
        self::assertNull($assignment);

        $versionAfter = (int) $this->pdo
            ->query('SELECT version FROM stardust_schema_version WHERE id = 1')
            ->fetchColumn();
        self::assertSame($versionBefore, $versionAfter, 'No-op reservation must not bump schema version.');
    }

    /** A field already holding a live slot cannot acquire a second one. */
    public function testReserveRejectsSecondLiveSlotForSameField(): void
    {
        $this->newProvisioner()->provision();
        $fieldId = $this->registerField('string');

        $first = $this->newReserver()->reserve($fieldId);
        self::assertNotNull($first);

        $this->expectException(PDOException::class);
        $this->newReserver()->reserve($fieldId);
    }

    /** Successful reservation bumps the schema version exactly once. */
    public function testReserveIncrementsSchemaVersion(): void
    {
        $this->newProvisioner()->provision();
        $fieldId = $this->registerField('int');

        $before = (int) $this->pdo
            ->query('SELECT version FROM stardust_schema_version WHERE id = 1')
            ->fetchColumn();

        $this->newReserver()->reserve($fieldId);

        $after = (int) $this->pdo
            ->query('SELECT version FROM stardust_schema_version WHERE id = 1')
            ->fetchColumn();
        self::assertSame($before + 1, $after);
    }

    /** ADR 0020 `slot_reserved` event lands on the structured-log stream. */
    public function testReserveEmitsStructuredLogEvent(): void
    {
        $this->newProvisioner()->provision();
        $fieldId = $this->registerField('datetime');

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $reserver = new SlotReserver(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new StdoutNdjsonLogger(new SystemClock(), $stream),
        );

        $assignment = $reserver->reserve($fieldId);
        self::assertNotNull($assignment);

        rewind($stream);
        $records = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        self::assertCount(1, $records);

        $decoded = json_decode($records[0], true);
        self::assertIsArray($decoded);
        self::assertSame('slot_reserved', $decoded['event'] ?? null);
        self::assertSame('registry', $decoded['source'] ?? null);
        self::assertSame($fieldId, $decoded['field_id'] ?? null);
        self::assertSame($assignment->slotAssignmentId, $decoded['slot_assignment_id'] ?? null);
        self::assertSame($assignment->pageId, $decoded['page_id'] ?? null);
        self::assertSame($assignment->slotColumn, $decoded['slot_column'] ?? null);
        self::assertSame('dt', $decoded['slot_type'] ?? null);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $decoded['correlation_id'] ?? '',
        );
    }

    /** Unknown field id surfaces an InvalidArgumentException before any registry write. */
    public function testReserveRejectsUnknownFieldId(): void
    {
        $this->newProvisioner()->provision();
        $this->expectException(\InvalidArgumentException::class);
        $this->newReserver()->reserve(99999);
    }
}
