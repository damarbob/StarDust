<?php

namespace StarDust\Libraries;

/**
 * Class RuntimeIndexer
 *
 * This service analyzes the "Model Definition" (JSON configuration of fields)
 * and automatically maintains MySQL Generated Virtual Columns to enable generic,
 * high-performance indexing on JSON attributes.
 * 
 * It bridges the gap between the flexibility of NoSQL (JSON storage) and
 * the performance of SQL (B-Tree indexing) by surfacing specific JSON keys
 * as "Virtual Columns" that can be indexed natively by MySQL.
 *
 * @package StarDust\Libraries
 */
class RuntimeIndexer
{
    /**
     * @var \CodeIgniter\Database\BaseConnection
     */
    protected $db;

    /**
     * @var string The physical table name where JSON data is stored.
     */
    protected $table = 'entry_data';

    /**
     * RuntimeIndexer constructor.
     * Establishes the database connection.
     */
    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Main Entry Point.
     * Synchronizes the physical database columns with the logical Model Definition.
     *
     * Iterates through the provided field definitions and ensures that for every
     * indexable field, a corresponding Virtual Column and DB Index exists.
     *
     * @param array      $modelDefinition The 'fields' array from the Model JSON configuration.
     * @param array|null &$existingColumns Optional cache of existing columns (passed by reference
     *                                      for performance - avoids copying large arrays between calls).
     *                                      Key = column name. Updated in-place as new columns are created.
     * @return void
     */
    public function syncIndexes(array $modelDefinition, ?array &$existingColumns = null)
    {
        $newColumns = [];
        $newIndexes = [];
        $columnsToCache = [];

        foreach ($modelDefinition as $field) {
            if ($this->shouldSkip($field)) continue;

            $slug    = $field['id'];
            $type    = $field['type'];
            $suffix  = $this->getSuffix($type);
            $colName = "v_{$slug}_{$suffix}";

            // Check existence to prevent duplicate DDL calls
            if ($existingColumns !== null) {
                // Optimization: Check against provided cache
                if (isset($existingColumns[$colName])) continue;
            } else {
                // Normal: Check against DB
                // Optimization: We skip this check here and rely on IF NOT EXISTS in the batch DDL
                // However, checking local cache if available is still good.
            }

            // Generate DDL parts
            $sqlDef  = $this->getSqlDefinition($type);
            $extract = $this->getExtractionLogic($slug, $type);

            $newColumns[] = "ADD COLUMN IF NOT EXISTS `{$colName}` {$sqlDef} GENERATED ALWAYS AS ({$extract}) VIRTUAL";
            $newIndexes[] = "ADD INDEX IF NOT EXISTS `idx_{$colName}` (`{$colName}`)";

            // Queue for cache update (only if successful)
            $columnsToCache[] = $colName;
        }

        // Execute Batch DDL
        if (!empty($newColumns)) {
            $this->executeBatchDDL($newColumns, $newIndexes);

            // Update cache ONLY after successful execution
            if ($existingColumns !== null) {
                foreach ($columnsToCache as $col) {
                    $existingColumns[$col] = true;
                }
            }
        }
    }

    /**
     * Executes the batched DDL commands.
     *
     * @param array $columns List of ADD COLUMN statements
     * @param array $indexes List of ADD INDEX statements
     * @return void
     */
    private function executeBatchDDL(array $columns, array $indexes)
    {
        try {
            // 1. Batch Create Virtual Columns
            // MariaDB supports ADD COLUMN IF NOT EXISTS, making this safe for race conditions.
            $sql = "ALTER TABLE `{$this->table}` " . implode(', ', $columns);
            $sql .= ", ALGORITHM=INSTANT";
            $this->tryExec($sql);

            // 2. Batch Create Indexes
            // MariaDB also supports multiple ADD INDEX in one ALTER TABLE
            // and supports ADD INDEX IF NOT EXISTS (MariaDB 10.0.2+)
            if (!empty($indexes)) {
                $sqlIndex = "ALTER TABLE `{$this->table}` " . implode(', ', $indexes);
                $sqlIndex .= ", ALGORITHM=NOCOPY, LOCK=NONE";
                $this->tryExec($sqlIndex);
            }
        } catch (\Throwable $e) {
            // Log failure
            log_message('error', "RuntimeIndexer Batch Failed: " . $e->getMessage());

            // Rethrow so the caller knows the operation failed.
            // This is critical for ensuring that model creation/updates don't silently succeed 
            // while the necessary database schema changes failed.
            throw $e;
        }
    }

    /**
     * Re-indexes all models found in the database.
     * 
     * This is an optimized batch operation that fetches all existing columns once
     * to avoid executing `SHOW COLUMNS` or `fieldExists` for every single field of every model.
     * 
     * Usage: Call this from a CLI command or migration to repair indexes.
     * 
     * @return array Statistics: ['models_processed' => int, 'columns_created' => int, 'columns_skipped' => int, 'failures' => array]
     */
    public function indexAllModels(): array
    {
        $stats = [
            'models_processed' => 0,
            'columns_created' => 0,
            'columns_skipped' => 0,
            'failures'        => [],
        ];

        // 1. Fetch all existing columns ONCE to avoid N*M queries
        //    We flip it to use isset() which is O(1)
        $existingColumnsInitial = array_flip($this->db->getFieldNames($this->table));
        $existingColumns = $existingColumnsInitial;

        // 2. Load all models
        //    We use the same scope 'stardust()' to ensure we get the JSON 'fields'
        /** @var \StarDust\Models\ModelsModel $modelsModel */
        $modelsModel = model('StarDust\Models\ModelsModel');
        $models      = $modelsModel->stardust()->get()->getResultArray();

        // 3. Sync each
        foreach ($models as $model) {
            try {
                if (empty($model['fields'])) continue;

                $fields = json_decode($model['fields'], true);
                if (json_last_error() !== JSON_ERROR_NONE) continue;

                $stats['models_processed']++;

                // Count columns before sync
                $columnsBefore = count($existingColumns);

                // Pass the cache so syncIndexes checks memory instead of DB
                $this->syncIndexes($fields, $existingColumns);

                // Track new columns created
                $columnsAfter = count($existingColumns);
                $newColumns = $columnsAfter - $columnsBefore;
                $stats['columns_created'] += $newColumns;
                $stats['columns_skipped'] += count($fields) - $newColumns;
            } catch (\Throwable $e) {
                $stats['failures'][$model['id'] ?? 'unknown'] = $e->getMessage();
                log_message('error', "RuntimeIndexer failed for model {$model['id']}: " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Identifies virtual columns that no longer correspond to any model field.
     * 
     * Scans all virtual columns in the entry_data table and compares them against
     * the field definitions in ALL models (including soft-deleted ones).
     * This ensures that temporarily deleted models don't cause their fields to be
     * incorrectly identified as orphaned.
     * 
     * @return array List of orphaned virtual column names
     */
    public function findOrphanedColumns(): array
    {
        // 1. Get all existing virtual columns from the database
        $allColumns = $this->db->getFieldNames($this->table);
        $virtualColumns = array_filter($allColumns, function ($col) {
            // Match pattern: v_{slug}_{suffix}
            return preg_match('/^v_.+_(num|str|dt)$/', $col);
        });


        // 2. Get ALL models (active AND soft-deleted) to avoid false positives
        //    A soft-deleted model might be restored, so its fields are NOT orphaned.
        //    ModelsModel::stardust() enforces a single state (active OR deleted), so we must query both.
        /** @var \StarDust\Models\ModelsModel $modelsModel */
        $modelsModel = model('StarDust\Models\ModelsModel');

        $activeModels  = $modelsModel->stardust(false)->get()->getResultArray();
        $deletedModels = $modelsModel->stardust(true)->get()->getResultArray();

        $models = array_merge($activeModels, $deletedModels);

        $activeColumns = [];
        foreach ($models as $model) {
            if (empty($model['fields'])) continue;

            $fields = json_decode($model['fields'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                log_message('warning', "RuntimeIndexer: Invalid JSON in model {$model['id']} fields");
                continue;
            }

            // Validate that fields is actually an array
            if (!is_array($fields)) {
                log_message('warning', "RuntimeIndexer: Fields is not an array in model {$model['id']}");
                continue;
            }

            foreach ($fields as $field) {
                // Validate field structure
                if (!is_array($field)) continue;
                if (!isset($field['id']) || !isset($field['type'])) {
                    log_message('warning', "RuntimeIndexer: Field missing 'id' or 'type' in model {$model['id']}");
                    continue;
                }

                if ($this->shouldSkip($field)) continue;

                $slug = $field['id'];
                $type = $field['type'];
                $suffix = $this->getSuffix($type);
                $colName = "v_{$slug}_{$suffix}";
                $activeColumns[$colName] = true;
            }
        }

        // 3. Find the difference: columns that exist but are not in any model
        $orphaned = [];
        foreach ($virtualColumns as $virtualColumn) {
            if (!isset($activeColumns[$virtualColumn])) {
                $orphaned[] = $virtualColumn;
            }
        }

        return $orphaned;
    }

    /**
     * Get all virtual columns from the database (for statistics/reporting).
     * 
     * @return array List of all virtual column names
     */
    public function getAllVirtualColumns(): array
    {
        $allColumns = $this->db->getFieldNames($this->table);
        return array_values(array_filter($allColumns, function ($col) {
            return preg_match('/^v_.+_(num|str|dt)$/', $col);
        }));
    }

    /**
     * Removes orphaned virtual columns and their associated indexes.
     * 
     * This operation is destructive and should only be called after user confirmation.
     * Returns statistics about the operation for reporting.
     * 
     * @param array $columnNames List of column names to remove
     * @return array ['success' => string[], 'failed' => array<string, string>]
     */
    public function removeOrphanedColumns(array $columnNames): array
    {
        $results = [
            'success' => [],
            'failed' => []
        ];

        foreach ($columnNames as $colName) {
            try {
                // Defensive validation: ensure column name matches expected pattern
                // This should always pass since findOrphanedColumns() pre-filters,
                // but we add it as a safety guard against direct API calls
                if (!preg_match('/^v_.+_(num|str|dt)$/', $colName)) {
                    throw new \InvalidArgumentException("Invalid column name format: {$colName}");
                }

                // NOTE: We do NOT use transactions here because MySQL DDL commits implicitly.
                // Wrapping in transStart/Complete provides a false sense of atomicity.

                // 1. Drop the index first
                $indexName = "idx_{$colName}";
                $this->tryExec("DROP INDEX IF EXISTS `{$indexName}` ON `{$this->table}`");

                // 2. Drop the virtual column
                $this->tryExec("ALTER TABLE `{$this->table}` DROP COLUMN IF EXISTS `{$colName}`");

                $results['success'][] = $colName;
                log_message('info', "RuntimeIndexer removed orphaned column: {$colName}");
            } catch (\Throwable $e) {
                $results['failed'][$colName] = $e->getMessage();
                log_message('error', "RuntimeIndexer failed to remove column {$colName}: " . $e->getMessage());
            }
        }

        return $results;
    }

    // --- Rules & Logic ---

    /**
     * Determines whether a specific field should be skipped for indexing.
     *
     * Rules:
     * 1. Security: Never index 'password' fields to avoid leaking hash patterns or existence.
     * 2. Performance: Skip 'textarea' or rich text fields (too large for efficient B-Trees).
     * 3. Complexity: Skip complex structures like 'checkboxes' or 'file' (arrays/objects)
     *    that don't map cleanly to a single scalar column.
     *
     * @param array $field The field definition array.
     * @return bool True if the field should NOT be indexed.
     */
    private function shouldSkip(array $field): bool
    {
        // 1. Security: Never index secrets
        if ($field['type'] === 'password') return true;

        // 2. Performance: Skip large text blobs (use Fulltext search instead if needed)
        if ($field['type'] === 'textarea' || ($field['className'] ?? '') === 'hyper-rich-text-field') return true;

        // 3. Complexity: Arrays (checkboxes) cannot use simple B-Tree virtual columns
        if ($field['type'] === 'checkboxes' || $field['type'] === 'file') return true;

        return false;
    }

    /**
     * Determines the column name suffix based on the data type.
     *
     * This helps in type-safe querying (e.g., numbers vs strings).
     * - 'num' for numeric types.
     * - 'dt' for date/time types.
     * - 'str' for default string types.
     *
     * @param string $type The field type (e.g., 'number', 'text').
     * @return string The suffix (e.g., 'num', 'dt', 'str').
     */
    private function getSuffix(string $type): string
    {
        return match ($type) {
            'number', 'range'        => 'num',
            'datetime-local', 'date' => 'dt',
            default                  => 'str',
        };
    }

    /**
     * Returns the SQL column definition for the virtual column.
     *
     * Defines the physical storage type that the JSON value will be cast to.
     *
     * @param string $type The field type.
     * @return string SQL column definition (e.g., 'DECIMAL(20,4)', 'VARCHAR(191)').
     */
    private function getSqlDefinition(string $type): string
    {
        return match ($type) {
            'number', 'range'        => 'DOUBLE',
            'datetime-local', 'date' => 'VARCHAR(191)', // Changed from DATETIME due to strict SQL mode issues
            default                  => 'VARCHAR(191)', // 191 fits comfortably in utf8mb4 indexes
        };
    }

    /**
     * Generates the MySQL expression to extract and cast the JSON value.
     *
     * The expression is used in the `GENERATED ALWAYS AS (...)` clause.
     * Handles JSON unquoting and type casting (e.g., casting string dates to DATETIME).
     *
     * @param string $slug The unique identifier of the field (JSON key).
     * @param string $type The field type.
     * @return string The full SQL expression for value extraction.
     */
    private function getExtractionLogic(string $slug, string $type): string
    {
        // Raw extraction
        $raw = "JSON_UNQUOTE(JSON_EXTRACT(`fields`, '$.{$slug}'))";

        return match ($type) {
            'number', 'range'        => "CAST({$raw} AS DOUBLE)",
            // Removed CAST to DATETIME for date/time types to avoid "cannot be used in GENERATED ALWAYS AS clause"
            default                  => $raw,
        };
    }

    /**
     * Executes the DDL commands to create the virtual column and its index.
     *
     * Wraps the operation in a transaction (though DDL defines implicit commit in MySQL,
     * the error handling is still centralized here).
     *
     * @param string $colName    The name of the new virtual column.
     * @param string $sqlDef     The SQL type definition.
     * @param string $expression The generation expression.
     * @return void
     */
    private function createVirtualColumn($colName, $sqlDef, $expression)
    {
        try {
            // NOTE: DDL statements in MySQL cause implicit commits (both before and after execution).
            // We do NOT use transactions here.

            // 1. Create the Virtual Column
            $this->tryExec("ALTER TABLE `{$this->table}` 
                              ADD COLUMN IF NOT EXISTS `{$colName}` {$sqlDef} 
                              GENERATED ALWAYS AS ({$expression}) VIRTUAL");

            // 2. Index It
            // Naming convention: idx_{column_name}
            $this->tryExec("CREATE INDEX IF NOT EXISTS `idx_{$colName}` ON `{$this->table}`(`{$colName}`)");
        } catch (\Throwable $e) {
            // Log failure but do not crash the request
            log_message('error', "RuntimeIndexer Failed ($colName): " . $e->getMessage());
        }
    }


    /**
     * Executes a query with retry logic for Windows file locking issues.
     * 
     * @param string $sql
     * @param int $maxAttempts
     * @return mixed Query result
     * @throws \Throwable
     */
    private function tryExec(string $sql, int $maxAttempts = 3)
    {
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            try {
                return $this->db->query($sql);
            } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
                // Retry only on specific transient errors:
                // 1205: Lock wait timeout exceeded
                // 1213: Deadlock found
                // 13:   Can't get stat of file (OS error 13 - Permission denied, common on Windows)
                $retryCodes = [1205, 1213, 13];

                if (in_array($e->getCode(), $retryCodes, true)) {
                    $attempts++;
                    if ($attempts >= $maxAttempts) {
                        throw $e;
                    }
                    // Log warning and wait
                    log_message('warning', "RuntimeIndexer DDL Locked (Code {$e->getCode()}, Attempt $attempts/$maxAttempts): " . $e->getMessage());
                    sleep(1);
                    continue;
                }

                // For other errors (e.g., Syntax Error 1064), throw immediately
                throw $e;
            } catch (\Throwable $e) {
                throw $e; // Don't retry other errors
            }
        }
    }
}
