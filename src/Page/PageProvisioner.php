<?php

declare(strict_types=1);

namespace StarDust\Page;

use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Phase 2 page provisioner.
 *
 * Creates a new `entry_slots_page_N` table using Empty-Table-Only DDL
 * (ADR 0012), then atomically inserts the matching `stardust_pages` row,
 * its full 60-row `stardust_slot_assignments` inventory (`status='free'`),
 * and a `stardust_schema_version.version` bump in a single registry
 * transaction (ADR 0017 §4.6 invariant #4). The page is never observed
 * with partial slot inventory.
 *
 * The Index Provisioning Policy (ADR 0003) is applied by the caller:
 * each slot column passed in `$filterableSlots` receives a composite
 * `(tenant_id, slot_column)` index on the new page; everything else is
 * created without one. Phase 5's Watcher will compute that list from
 * pending unmapped fields; Phase 2 takes it as a parameter so the class
 * can be used in isolation.
 *
 * The class is not a daemon — it has no polling loop, no singleton guard,
 * and no advisory-lock acquisition. Phase 5 will wrap an instance inside
 * the Watcher (ADR 0008) and add `GET_LOCK('stardust_page_provision', …)`.
 */
final class PageProvisioner
{
    public const STRING_SLOTS   = 25;
    public const INT_SLOTS      = 15;
    public const NUMERIC_SLOTS  = 10;
    public const DATETIME_SLOTS = 10;
    public const SLOTS_PER_PAGE = 60;

    /**
     * Per slot-type family: count of slot columns and the MySQL column type
     * used in the page DDL. Slot family code matches the
     * `stardust_slot_assignments.slot_type` ENUM.
     */
    private const SLOT_TYPE_DEFINITIONS = [
        'str' => ['count' => self::STRING_SLOTS,   'mysql_type' => 'VARCHAR(255)'],
        'int' => ['count' => self::INT_SLOTS,      'mysql_type' => 'BIGINT'],
        'num' => ['count' => self::NUMERIC_SLOTS,  'mysql_type' => 'DOUBLE'],
        'dt'  => ['count' => self::DATETIME_SLOTS, 'mysql_type' => 'DATETIME'],
    ];

    private readonly string $provisionerIdentity;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        ?string $provisionerIdentity = null,
    ) {
        $this->provisionerIdentity = $provisionerIdentity
            ?? ((gethostname() ?: 'unknown') . '/' . (string) getmypid());
    }

    /**
     * Provision a new extension page.
     *
     * @param list<string> $filterableSlots Slot column names (e.g. `i_str_01`) that should
     *                                      receive a composite `(tenant_id, slot_column)` index.
     *                                      Unknown column names throw `InvalidArgumentException`.
     * @return int The new `stardust_pages.id`, equal to the X in `entry_slots_page_X`.
     */
    public function provision(array $filterableSlots = []): int
    {
        $this->validateFilterableSlots($filterableSlots);

        $pageNumber = (int) $this->pdo
            ->query('SELECT COALESCE(MAX(id), 0) + 1 FROM stardust_pages')
            ->fetchColumn();
        $tableName = "entry_slots_page_{$pageNumber}";

        // DDL auto-commits in MySQL. CREATE TABLE IF NOT EXISTS keeps the
        // step safe to retry after a crash between this point and the
        // registry transaction below — the rolled-back attempt left an
        // empty page behind whose name we will rediscover on the next call.
        $this->pdo->exec($this->buildPageDdl($tableName, $filterableSlots));

        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $insertPage = $this->pdo->prepare(
                'INSERT INTO stardust_pages (id, table_name, provisioned_at, provisioned_by)'
                . ' VALUES (?, ?, ?, ?)'
            );
            $insertPage->execute([$pageNumber, $tableName, $now, $this->provisionerIdentity]);

            $this->insertSlotInventory($pageNumber, $now);

            $bumpVersion = $this->pdo->prepare(
                'UPDATE stardust_schema_version'
                . ' SET version = version + 1, updated_at = ?'
                . ' WHERE id = 1'
            );
            $bumpVersion->execute([$now]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->logger->info('page provisioned', [
            'event'            => 'page_provisioned',
            'source'           => 'registry',
            'page_id'          => $pageNumber,
            'table_name'       => $tableName,
            'filterable_slots' => array_values($filterableSlots),
        ]);

        return $pageNumber;
    }

    /** @return list<string> All 60 slot column names in declaration order. */
    public static function allSlotColumns(): array
    {
        $out = [];
        foreach (array_keys(self::SLOT_TYPE_DEFINITIONS) as $type) {
            foreach (self::slotColumnsForType($type) as $col) {
                $out[] = $col;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $filterableSlots
     */
    private function validateFilterableSlots(array $filterableSlots): void
    {
        $valid = array_flip(self::allSlotColumns());
        foreach ($filterableSlots as $slot) {
            if (!is_string($slot) || !isset($valid[$slot])) {
                $rendered = is_string($slot) ? $slot : '(non-string)';
                throw new InvalidArgumentException(
                    "PageProvisioner: '{$rendered}' is not a valid slot column."
                );
            }
        }
    }

    /**
     * @param list<string> $filterableSlots
     */
    private function buildPageDdl(string $tableName, array $filterableSlots): string
    {
        $lines = [
            "CREATE TABLE IF NOT EXISTS {$tableName} (",
            '    entry_id  BIGINT NOT NULL,',
            '    tenant_id BIGINT NOT NULL,',
        ];

        foreach (self::allSlotColumns() as $col) {
            $lines[] = sprintf('    %s %s NULL DEFAULT NULL,', $col, self::columnSqlType($col));
        }

        $lines[] = '    PRIMARY KEY (entry_id),';
        $lines[] = sprintf('    KEY ix_%s_tenant (tenant_id),', $tableName);

        foreach ($filterableSlots as $slot) {
            $lines[] = sprintf('    KEY ix_%s_%s (tenant_id, %s),', $tableName, $slot, $slot);
        }

        $lines[] = sprintf(
            '    CONSTRAINT fk_%s_entry FOREIGN KEY (entry_id) REFERENCES entry_data (id) ON DELETE CASCADE',
            $tableName
        );
        $lines[] = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

        return implode("\n", $lines);
    }

    private function insertSlotInventory(int $pageId, string $now): void
    {
        $placeholders = [];
        $params = [];
        foreach (array_keys(self::SLOT_TYPE_DEFINITIONS) as $slotType) {
            foreach (self::slotColumnsForType($slotType) as $col) {
                $placeholders[] = '(?, ?, ?, ?, ?)';
                array_push($params, $pageId, $col, $slotType, 'free', $now);
            }
        }

        $sql = 'INSERT INTO stardust_slot_assignments'
            . ' (page_id, slot_column, slot_type, status, updated_at)'
            . ' VALUES ' . implode(',', $placeholders);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /** @return list<string> */
    private static function slotColumnsForType(string $type): array
    {
        $count = self::SLOT_TYPE_DEFINITIONS[$type]['count'];
        $cols = [];
        for ($i = 1; $i <= $count; $i++) {
            $cols[] = sprintf('i_%s_%02d', $type, $i);
        }
        return $cols;
    }

    private static function columnSqlType(string $col): string
    {
        $parts = explode('_', $col);
        return self::SLOT_TYPE_DEFINITIONS[$parts[1]]['mysql_type'];
    }
}
