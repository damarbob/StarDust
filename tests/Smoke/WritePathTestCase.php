<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use StarDust\Bootstrap\Bootstrapper;
use StarDust\Clock\SystemClock;
use StarDust\Page\PageProvisioner;
use StarDust\Slot\SlotReserver;

/**
 * Shared scaffolding for Phase 3 write-path smoke tests.
 *
 * Provides the env-gated MySQL connection, the table-drop sweep, and
 * a handful of registry-seed helpers (model + field + reserved slot)
 * so individual tests can focus on their behavioural assertions.
 */
abstract class WritePathTestCase extends TestCase
{
    protected const PHASE_1_TABLES = [
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

    protected PDO $pdo;

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

    protected function dropEverything(): void
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

    /**
     * Provision page 1 (no filterable slots) so str/int/num/dt slots
     * are available for reservation in tests that want them.
     */
    protected function provisionPage(array $filterableSlots = []): int
    {
        return (new PageProvisioner(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new NullLogger(),
            provisionerIdentity: 'phpunit/0',
        ))->provision($filterableSlots);
    }

    /**
     * Insert a model row and return its id.
     */
    protected function createModel(int $tenantId = 1, ?string $name = null): int
    {
        $name ??= 'model_' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_models (tenant_id, name, created_at)'
            . ' VALUES (?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$tenantId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert a field row and return its id.
     */
    protected function createField(
        int $modelId,
        string $declaredType = 'string',
        bool $isFilterable = false,
        ?string $name = null,
    ): int {
        $name ??= 'field_' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_fields'
            . ' (model_id, name, declared_type, is_filterable, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([$modelId, $name, $declaredType, $isFilterable ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Reserve a slot for a field (calls Phase 2 SlotReserver).
     */
    protected function reserveSlotFor(int $fieldId): void
    {
        (new SlotReserver(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new NullLogger(),
        ))->reserve($fieldId);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: string} [modelId, fieldId, pageId, fieldName]
     */
    protected function setupModelWithReservedField(
        int $tenantId = 1,
        string $declaredType = 'string',
    ): array {
        $pageId = $this->provisionPage();
        $modelId = $this->createModel($tenantId);
        $fieldName = 'field_' . bin2hex(random_bytes(4));
        $fieldId = $this->createField($modelId, $declaredType, false, $fieldName);
        $this->reserveSlotFor($fieldId);
        return [$modelId, $fieldId, $pageId, $fieldName];
    }
}
