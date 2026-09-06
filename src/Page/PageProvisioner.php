<?php

declare(strict_types=1);

namespace StarDust\Page;

use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Support\PdoQuery;
use StarDust\Support\UuidV4;
use Throwable;

/**
 * Phase 2 page provisioner.
 *
 * Creates a new `entry_slots_page_N` table using Empty-Table-Only DDL
 * (ADR 0012), then atomically inserts the matching `stardust_pages` row,
 * its `stardust_slot_assignments` inventory (`status='free'`), and a
 * `stardust_schema_version.version` bump in a single registry
 * transaction (ADR 0017 §4.6 invariant #4). The page is never observed
 * with partial slot inventory.
 *
 * The Index Provisioning Policy (ADR 0003) is applied by the caller:
 * each slot column passed in `$filterableSlots` receives a composite
 * `(tenant_id, slot_column)` index on the new page. Phase 5's Watcher
 * computes that list from pending demand plus ADR 0042 headroom; the
 * class takes it as a parameter so it can be used in isolation.
 *
 * **A page carries exactly the columns it indexes** (ADR 0043). It used
 * to carry all sixty and index a handful, which made the other rows of
 * its inventory describe capacity no reservation path could take — a
 * filterable field's slot must be indexed (ADR 0004) and a
 * non-filterable field holds no slot at all (ADR 0034), so an unindexed
 * column could not legally be occupied by anything. Now `free` and
 * `claimable` are the same set, and `CapacityReporter`'s numbers are
 * true without a new column or predicate. Pages provisioned before that
 * decision keep their sixty columns for good (ADR 0012 is
 * forward-only), which is why `IndexedSlotPredicate` is permanent
 * rather than transitional.
 *
 * The class is not a daemon — it has no polling loop, no singleton guard,
 * and no advisory-lock acquisition. Phase 5 will wrap an instance inside
 * the Watcher (ADR 0008) and add `GET_LOCK('stardust_page_provision', …)`.
 */
final class PageProvisioner
{
    /**
     * Per-family layout: the **upper bound** on how many columns of one
     * family a page may carry, not how many it does. Since ADR 0043 a
     * page is created with exactly the columns it indexes, so its
     * capacity is whatever the caller asked for — read it from that
     * page's own inventory rows, never from a constant. (There is
     * deliberately no `SLOTS_PER_PAGE` any more: a page-wide total
     * stopped being a fact about pages.)
     */
    public const STRING_SLOTS   = 25;
    public const INT_SLOTS      = 15;
    public const NUMERIC_SLOTS  = 10;
    public const DATETIME_SLOTS = 10;

    /**
     * String slots are `TEXT` so they can hold the full normative QueryFilter
     * string bound (4096 chars, `FilterLimits::DEFAULT_MAX_STRING_LENGTH`).
     * `VARCHAR(4096)` cannot: 25 string slots × 4096 × 4 bytes (utf8mb4)
     * blows MySQL's 65,535-byte row-definition limit (errno 1118), which
     * counts every VARCHAR in full — TEXT counts only its ~12-byte pointer.
     *
     * The filterable composite `(tenant_id, slot_column)` index covers a
     * 766-char prefix: 766 utf8mb4 chars × 4 bytes + an 8-byte BIGINT
     * tenant_id = 3072 bytes, exactly the InnoDB key-size limit under
     * ROW_FORMAT=DYNAMIC (COMPACT/REDUNDANT cap keys at 767 bytes, errno
     * 1071 — hence the page DDL pins DYNAMIC explicitly). MySQL rechecks
     * the full column value behind every prefix-index access, so all 12
     * filter operators stay exact (ADR 0030).
     */
    public const STRING_INDEX_PREFIX = 766; // 766*4 + 8-byte tenant_id = 3072 = InnoDB DYNAMIC key limit

    /**
     * Per slot-type family: count of slot columns and the MySQL column type
     * used in the page DDL. Slot family code matches the
     * `stardust_slot_assignments.slot_type` ENUM.
     */
    private const SLOT_TYPE_DEFINITIONS = [
        'str' => ['count' => self::STRING_SLOTS,   'mysql_type' => 'TEXT'],
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
     * The page is created with **exactly** the columns it indexes (ADR
     * 0043), and its slot inventory names the same set — so every `free`
     * row describes capacity a reservation can actually take. Passing an
     * empty list is rejected rather than producing a page with no slots:
     * such a page adds nothing to the capacity totals, so the Watcher's
     * low-capacity trigger would never clear and it would provision one
     * per tick forever.
     *
     * @param list<string> $filterableSlots Slot column names (e.g. `i_str_01`). Each is created
     *                                      on the page and receives a composite
     *                                      `(tenant_id, slot_column)` index. Unknown column names
     *                                      and an empty list both throw `InvalidArgumentException`.
     * @param ?string      $correlationId   The enclosing operation's id, per ADR 0020's
     *                                      "carried through any sub-events emitted within the
     *                                      same operation". The Watcher passes its cycle id so
     *                                      `page_provisioned` joins the `provision_started` that
     *                                      ordered it; `null` means no enclosing operation and
     *                                      mints one.
     * @return int The new `stardust_pages.id`, equal to the X in `entry_slots_page_X`.
     */
    public function provision(array $filterableSlots, ?string $correlationId = null): int
    {
        $filterableSlots = $this->validateFilterableSlots($filterableSlots);

        if ($filterableSlots === []) {
            throw new InvalidArgumentException(
                'PageProvisioner: a page must carry at least one slot column.'
                . ' Since ADR 0043 a page is created with exactly the columns it indexes,'
                . ' so an empty list would produce a page with no claimable capacity.'
            );
        }

        $pageNumber = (int) PdoQuery::run(
            $this->pdo,
            'SELECT COALESCE(MAX(id), 0) + 1 FROM stardust_pages',
        )->fetchColumn();
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

            $this->insertSlotInventory($pageNumber, $filterableSlots, $now);

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
            'correlation_id'   => $correlationId ?? UuidV4::generate(),
            'page_id'          => $pageNumber,
            'table_name'       => $tableName,
            // Already a normalised list — reassigned from
            // validateFilterableSlots() at the top of provision().
            'filterable_slots' => $filterableSlots,
        ]);

        return $pageNumber;
    }

    /**
     * The four slot families, in declaration order.
     *
     * Public because ADR 0042's provisioning planner indexes headroom in
     * every family rather than only demanded ones, so it needs the
     * universe rather than a caller-supplied list. Keeping it derived
     * from `SLOT_TYPE_DEFINITIONS` is what stops the family set drifting
     * from the DDL, on the same reasoning as {@see self::slotColumnsForType()}.
     *
     * @return list<string>
     */
    public static function slotFamilies(): array
    {
        return array_keys(self::SLOT_TYPE_DEFINITIONS);
    }

    /**
     * The caller's column list, re-ordered into layout order.
     *
     * The page DDL, its inventory rows and their `slot_type` all derive
     * from one traversal, so a page's shape does not depend on the order
     * the planner happened to emit its columns in.
     *
     * @param  list<string> $columns
     * @return list<string>
     */
    private static function orderColumns(array $columns): array
    {
        $wanted = array_flip($columns);

        $out = [];
        foreach (self::allSlotColumns() as $col) {
            if (isset($wanted[$col])) {
                $out[] = $col;
            }
        }

        return $out;
    }

    /** @return list<string> All 60 slot column names in declaration order. */
    public static function allSlotColumns(): array
    {
        $out = [];
        foreach (self::slotFamilies() as $type) {
            foreach (self::slotColumnsForType($type) as $col) {
                $out[] = $col;
            }
        }
        return $out;
    }

    /**
     * Validates each requested slot is known and silently deduplicates the
     * list so a caller (e.g. a future Phase 5 Watcher that converges
     * multiple pending-field events) cannot trigger a MySQL errno 1061
     * "Duplicate key name" by passing the same column twice.
     *
     * Deliberately typed `array<mixed>` rather than `list<string>`: this is the
     * function that *establishes* that shape, and PHP enforces none of it at
     * runtime. `provision()` is public API of a published package, so a consumer
     * can pass `['i_str_01', 123]` past the `array` hint with nothing raised. The
     * `is_string()` check below is what turns that into a clear exception instead
     * of an obscure SQL error — it is unreachable only if you believe the
     * docblock. Covered by `PageProvisionerTest::testProvisionRejectsNonStringSlotColumn`.
     *
     * Returns the narrowed list rather than mutating a reference, so the
     * `array<mixed> → list<string>` transition is visible to the caller
     * and to static analysis.
     *
     * @param  array<mixed> $filterableSlots
     * @return list<string>
     */
    private function validateFilterableSlots(array $filterableSlots): array
    {
        $valid = array_flip(self::allSlotColumns());
        $checked = [];
        foreach ($filterableSlots as $slot) {
            if (!is_string($slot) || !isset($valid[$slot])) {
                $rendered = is_string($slot) ? $slot : '(non-string)';
                throw new InvalidArgumentException(
                    "PageProvisioner: '{$rendered}' is not a valid slot column."
                );
            }
            $checked[] = $slot;
        }
        return array_values(array_unique($checked));
    }

    /**
     * @param list<string> $filterableSlots
     */
    private function buildPageDdl(string $tableName, array $filterableSlots): string
    {
        $columns = self::orderColumns($filterableSlots);

        $lines = [
            "CREATE TABLE IF NOT EXISTS {$tableName} (",
            '    entry_id  BIGINT NOT NULL,',
            '    tenant_id BIGINT NOT NULL,',
        ];

        foreach ($columns as $col) {
            $lines[] = sprintf('    %s %s NULL DEFAULT NULL,', $col, self::columnSqlType($col));
        }

        $lines[] = '    PRIMARY KEY (entry_id),';
        $lines[] = sprintf('    KEY ix_%s_tenant (tenant_id),', $tableName);

        foreach ($columns as $slot) {
            $lines[] = sprintf('    KEY ix_%s_%s (tenant_id, %s),', $tableName, $slot, self::indexedColumnExpr($slot));
        }

        $lines[] = sprintf(
            '    CONSTRAINT fk_%s_entry FOREIGN KEY (entry_id) REFERENCES entry_data (id) ON DELETE CASCADE',
            $tableName
        );
        // ROW_FORMAT=DYNAMIC is load-bearing: the 766-char string-slot index
        // prefix needs the 3072-byte key limit; COMPACT/REDUNDANT cap at 767
        // bytes and would fail CREATE TABLE with errno 1071 on servers whose
        // innodb_default_row_format is not dynamic.
        $lines[] = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC';

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $filterableSlots
     */
    private function insertSlotInventory(int $pageId, array $filterableSlots, string $now): void
    {
        $wanted = array_flip($filterableSlots);

        $placeholders = [];
        $params = [];
        foreach (self::slotFamilies() as $slotType) {
            foreach (self::slotColumnsForType($slotType) as $col) {
                if (! isset($wanted[$col])) {
                    continue;
                }
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

    /**
     * The slot column names of one type family, in declaration order.
     *
     * Public because the Watcher's provisioning planner picks the
     * columns to index from this list and uses its length as the
     * family's per-page capacity — so the layout has exactly one
     * definition. Mirrors {@see self::allSlotColumns()}.
     *
     * @return list<string>
     */
    public static function slotColumnsForType(string $type): array
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

    /**
     * Index-column expression for a filterable slot's composite key.
     *
     * String slots are `TEXT`, which MySQL refuses to index without an
     * explicit prefix length; the composite `(tenant_id, slot)` key caps
     * that prefix at `STRING_INDEX_PREFIX` utf8mb4 chars (ADR 0030).
     * Fixed-width families (int/num/dt) are indexed in full.
     */
    private static function indexedColumnExpr(string $col): string
    {
        return explode('_', $col)[1] === 'str'
            ? sprintf('%s(%d)', $col, self::STRING_INDEX_PREFIX)
            : $col;
    }
}
