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
     * @param array $modelDefinition The 'fields' array from the Model JSON configuration.
     * @return void
     */
    /**
     * Main Entry Point.
     * Synchronizes the physical database columns with the logical Model Definition.
     *
     * Iterates through the provided field definitions and ensures that for every
     * indexable field, a corresponding Virtual Column and DB Index exists.
     *
     * @param array      $modelDefinition The 'fields' array from the Model JSON configuration.
     * @param array|null $existingColumns Optional cache of existing columns (key = column name) for bulk performance.
     * @return void
     */
    public function syncIndexes(array $modelDefinition, ?array &$existingColumns = null)
    {
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
                if ($this->db->fieldExists($colName, $this->table)) continue;
            }

            // Generate DDL
            $sqlDef  = $this->getSqlDefinition($type);
            $extract = $this->getExtractionLogic($slug, $type);

            // Execute safely
            $this->createVirtualColumn($colName, $sqlDef, $extract);

            // Update cache after creation
            if ($existingColumns !== null) {
                $existingColumns[$colName] = true;
            }
        }
    }

    /**
     * Re-indexes all models found in the database.
     * 
     * This is an optimized batch operation that fetches all existing columns once
     * to avoid executing `SHOW COLUMNS` or `fieldExists` for every single field of every model.
     * 
     * Usage: Call this from a CLI command or migration migration to repair indexes.
     */
    public function indexAllModels()
    {
        // 1. Fetch all existing columns ONCE to avoid N*M queries
        //    We flip it to use isset() which is O(1)
        $existingColumns = array_flip($this->db->getFieldNames($this->table));

        // 2. Load all models
        //    We use the same scope 'stardust()' to ensure we get the JSON 'fields'
        /** @var \StarDust\Models\ModelsModel $modelsModel */
        $modelsModel = model('StarDust\Models\ModelsModel');
        $models      = $modelsModel->stardust()->get()->getResultArray();

        // 3. Sync each
        foreach ($models as $model) {
            if (empty($model['fields'])) continue;

            $fields = json_decode($model['fields'], true);
            if (json_last_error() !== JSON_ERROR_NONE) continue;

            // Pass the cache so syncIndexes checks memory instead of DB
            $this->syncIndexes($fields, $existingColumns);
        }
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
            'number', 'range'        => 'DECIMAL(20,4)',
            'datetime-local', 'date' => 'DATETIME',
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
            'number', 'range'        => "CAST({$raw} AS DECIMAL(20,4))",
            'datetime-local', 'date' => "CAST(NULLIF({$raw}, '') AS DATETIME)",
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
            // Transaction ensures we don't get a column without an index (or vice versa)
            $this->db->transStart();

            // 1. Create the Virtual Column
            $this->db->query("ALTER TABLE `{$this->table}` 
                              ADD COLUMN IF NOT EXISTS `{$colName}` {$sqlDef} 
                              GENERATED ALWAYS AS ({$expression}) VIRTUAL");

            // 2. Index It
            // Naming convention: idx_{column_name}
            $this->db->query("CREATE INDEX IF NOT EXISTS `idx_{$colName}` ON `{$this->table}`(`{$colName}`)");

            $this->db->transComplete();
        } catch (\Throwable $e) {
            // Log failure but do not crash the request
            log_message('error', "RuntimeIndexer Failed ($colName): " . $e->getMessage());
        }
    }
}
