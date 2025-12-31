# Frequently Asked Questions

## General Usage

### Why use `stardust()` instead of standard Model methods?

While `EntriesModel` extends CodeIgniter's standard Model, the `stardust()` method provides several key advantages:

- **Pre-configured JOINs**: Automatically includes entry data, model metadata, and user relationships
- **Virtual Column SELECT clauses**: Ready to work with indexed dynamic fields
- **Soft-delete filtering**: Automatically filters active/deleted entries
- **Fresh query state**: Prevents query contamination from previous operations

For simple queries, you can still use standard Model methods. For StarDust-specific queries with dynamic fields, use `stardust()`.

**Example:**

```php
$entriesModel = model('StarDust\Models\EntriesModel');

// Using stardust() for dynamic field queries
$results = $entriesModel->stardust()
    ->where('v_price_01_num >', 1000)
    ->where('v_sku_01_str', 'PROD-001')
    ->get()
    ->getResultArray();
```

---

## Advanced Features

### How do I search non-indexed fields?

If you need to search fields that are **not** indexed (e.g., fields marked as `textarea` or other non-indexed types), you can use the slower `likeFields` method.

> ⚠️ **Performance Warning**: This performs a full table scan inside the JSON blob and is significantly slower than indexed queries.

```php
// Performs a full table scan inside the JSON blob (slower)
$entriesModel->stardust()->likeFields([
    ['field' => 'description_01', 'value' => 'fragile']
])->get();
```

**When to use this:**

- Searching text fields not defined in `model_fields`
- Full-text search requirements
- Temporary queries during development

**Better alternative:** Define the field in `model_fields` and run `php spark stardust:generate-indexes` to create an indexed virtual column.

---

### How do I use the Syntax Processor for dynamic queries?

The `SyntaxProcessor` is designed for scenarios where query logic is passed as JSON, such as from a frontend API or stored configuration. It parses placeholders and executes the query dynamically.

**Use cases:**

- API-driven query builders
- User-configurable reports
- Dynamic dashboard widgets
- Stored query templates

**Example:**

```php
use StarDust\Libraries\SyntaxProcessor;

$processor = new SyntaxProcessor();

// A JSON representation of a database query
// {{field:price_01}} tells the processor to extract the 'price_01' key from the JSON column
$jsonRequest = '{
    "type": "data",
    "content": {
        "table": "entries",
        "select": "id, {{field:price_01}} as price",
        "where": [
            {"column": "model_id", "operator": "=", "value": 1}
        ]
    }
}';

$result = $processor->process($jsonRequest);
// Returns the result set as a JSON string
```

**Helper function:**

```php
// Convenience wrapper
$processor = syntax_processor();
$result = $processor->process($jsonRequest);
```

---

## Maintenance & CLI Tools

### What CLI commands are available for maintenance?

StarDust provides several CLI commands to help manage your dynamic data:

#### `stardust:generate-indexes`

Regenerates virtual columns and B-Tree indexes based on your `model_fields` definitions.

```bash
php spark stardust:generate-indexes
```

**When to run:**

- **Repairing indexes** after direct database modifications
- **Regenerating all indexes** after a database restore
- **Initial setup** when migrating from v0.1.x
- **Never needed for normal operation** - `modelsManager` handles this automatically

> ℹ️ **Note:** Virtual columns are **automatically created** when you use `modelsManager->create()` or `modelsManager->update()`. This command is only for manual regeneration or repair scenarios.

---

#### `stardust:map-current`

Updates the `current_entry_data_id` pointers to ensure queries return the latest version of each entry.

```bash
php spark stardust:map-current
```

**When to run:**

- After bulk data imports
- If you notice stale data being returned
- After database restoration
- Periodically as maintenance (optional)

**Performance note:** This operation uses O(1) joins for optimal performance on large datasets.

---

#### `stardust:cleanup-columns`

Removes orphaned virtual columns that no longer match any model field definitions.

```bash
php spark stardust:cleanup-columns        # Interactive mode with confirmations
php spark stardust:cleanup-columns --dry-run   # Preview mode (no changes)
php spark stardust:cleanup-columns -d          # Short form of dry-run
```

**When to run:**

- When you've removed fields from model definitions and want to clean up the database
- As part of database maintenance to remove unused indexes
- After multiple model iterations where fields have been deprecated
- To reclaim disk space from unused virtual columns

> 💡 **Tip:** Always run with `--dry-run` first to preview which columns will be deleted before performing the actual cleanup.

**What it does:**

1. Scans all virtual columns in `entry_data` table (columns matching `v_*_num`, `v_*_str`, `v_*_dt`)
2. Compares against **ALL model field definitions** (including soft-deleted models to avoid false positives)
3. Validates field structure and handles malformed data gracefully
4. Calculates statistics (total columns, orphaned count, percentage)
5. **Warns** if more than 50% of columns would be deleted (suspicious)
6. Identifies orphaned columns that don't match any current field
7. In dry-run mode: Shows preview and exits without making changes
8. In normal mode: Prompts for double confirmation before deletion
9. Drops unused virtual columns and their associated indexes
10. Reports detailed success/failure statistics

> ⚠️ This operation is **destructive** and will permanently remove virtual columns. The command includes double confirmation prompts to prevent accidental deletion. Make sure to backup your database before running.

> 🚨 **Soft-deleted models are protected**: The cleanup command checks ALL models, including soft-deleted ones. This prevents accidentally removing columns from models that might be restored later.

**Example output (dry-run):**

```
DRY-RUN MODE: No changes will be made

Analyzing orphaned virtual columns...
Found 3 orphaned column(s) out of 10 total virtual columns (30%):
  -  v_old_price_01_num
  - v_discontinued_sku_02_str
  - v_legacy_date_03_dt

Dry-run complete. No changes were made.
To actually delete these columns, run without --dry-run flag.
```

**Example output (normal mode with warning):**

```
Analyzing orphaned virtual columns...
Found 8 orphaned column(s) out of 10 total virtual columns (80%):
  - v_old_price_01_num
  - v_discontinued_sku_02_str
  - v_legacy_date_03_dt
  - ... (5 more)

⚠ WARNING: More than 50% of virtual columns would be deleted!
  This seems suspicious. Please verify your model definitions are correct.
  Consider running with --dry-run first if unsure.

Do you want to proceed with deletion? [y, n]: y
Are you absolutely sure? This cannot be undone! [y, n]: y

Removing orphaned columns...

✓ Successfully removed 7 column(s):
  • v_old_price_01_num
  • v_discontinued_sku_02_str
  • ... (5 more)

✗ Failed to remove 1 column(s):
  • v_legacy_date_03_dt
    Reason: Cannot drop column 'v_legacy_date_03_dt': needed in a foreign key constraint

Cleanup Completed with errors. Check logs for details.
```

**Robustness features:**

- **Dry-run mode**: Preview changes without making modifications (`--dry-run` or `-d`)
- **Suspicious deletion detection**: Warns when >50% of columns would be deleted
- **Soft-delete aware**: Checks all models including soft-deleted ones to avoid false positives
- **Field validation**: Validates JSON structure and field definitions before processing
- **Input validation**: Guards against invalid column names even when called directly
- **Transaction safety**: Each column deletion is wrapped in a transaction
- **Detailed reporting**: Shows statistics and exactly which columns succeeded/failed
- **Graceful degradation**: Continues processing even if individual columns fail
- **Error logging**: All failures are logged for debugging

---

#### `stardust:convert-fields`

Migrates `entry_data.fields` from the legacy array-of-objects format (v0.1) to the new key-value format (v0.2+).

```bash
php spark stardust:convert-fields
```

**When to run:**

- Only during migration from v0.1.x to v0.2.0+
- Do not run on fresh installations

---

## Migration & Upgrades

### How do I upgrade from v0.1.x to v0.2.0+?

This update introduces Virtual Columns for high-performance querying, which requires a significant change to the database schema and data format.

> 🛑 **STRICT EXECUTION ORDER REQUIRED**
>
> You **MUST** run the following commands in the exact order listed below. Failure to do so may result in data inconsistency or errors.

#### Step 1: Run Database Migrations

Updates the schema to support virtual columns and version pointers.

```bash
php spark migrate -n StarDust
```

#### Step 2: Convert Data Format

Converts your existing JSON data to the new key-value format required by the Virtual Column indexer.

```bash
php spark stardust:convert-fields
```

#### Step 3: Generate Indexes

Scans your `model_fields` and creates the necessary Virtual Columns and B-Tree Indexes in the database.

```bash
php spark stardust:generate-indexes
```

#### Step 4: Map Current Versions

Populates the `current_entry_data_id` placeholders to ensure queries return the correct latest data.

```bash
php spark stardust:map-current
```

---

### What breaking changes should I be aware of in v0.2.0+?

**Data Format:**

- Entry fields changed from array-of-objects to key-value pairs
- Run `stardust:convert-fields` to migrate existing data

**Query Methods:**

- Virtual columns require specific naming convention: `v_{field_id}_{suffix}`
- Use `stardust()` method for optimal performance with dynamic fields

**Database Schema:**

- New `current_entry_data_id` column in `entries` table
- Virtual columns automatically generated in `entry_data` table

### Deprecation Roadmap

The following legacy methods are **deprecated** in v0.2.0 and will be **removed in v0.3.0**:

- `EntriesModel::getCustomBuilder()`
- `EntriesModel::getDeletedCustomBuilder()`
- `EntriesModel::whereFields()`

Please migrate your code to use the new `stardust()` builder and virtual columns before upgrading to v0.3.0.

---

## Troubleshooting

### Why are my queries returning stale data?

If you're seeing outdated entry data, the `current_entry_data_id` pointers may be out of sync.

**Solution:**

```bash
php spark stardust:map-current
```

This regenerates the version pointers to ensure queries return the latest data.

---

### Why is my WHERE clause on a dynamic field not working?

**Check these common issues:**

1. **Incorrect virtual column name** - Ensure you're using the right suffix (`_num`, `_str`, `_dt`)
2. **Index not generated** - Run `php spark stardust:generate-indexes`
3. **Field not defined in model_fields** - Add the field definition and regenerate indexes
4. **Using standard model methods** - Use `stardust()` method instead

**Correct example:**

```php
// ✅ Correct - uses virtual column with stardust()
$entriesModel->stardust()
    ->where('v_price_01_num >', 1000)
    ->get();

// ❌ Incorrect - tries to query JSON directly
$entriesModel->where('fields', 'LIKE', '%price_01%')
    ->get();
```

---

### Are virtual columns created automatically?

**Yes!** When you use `modelsManager`, virtual columns are automatically created.

**How it works:**

```php
$manager = service('modelsManager');

// Virtual columns are created automatically during this call
$modelId = $manager->create([
    'name' => 'Products',
    'slug' => 'products',
    'model_fields' => json_encode([
        ['id' => 'price_01', 'label' => 'Price', 'type' => 'number'], // Creates v_price_01_num
        ['id' => 'sku_01', 'label' => 'SKU', 'type' => 'text']         // Creates v_sku_01_str
    ])
], $userId);

// Also automatic during update
$manager->update($modelId, $updatedData, $userId);
```

The `RuntimeIndexer` is called automatically by `modelsManager` and creates virtual columns for any new fields.

---

### Why doesn't the RuntimeIndexer remove old virtual columns?

The `RuntimeIndexer` uses an **addition-only strategy** for safety and reusability:

**Reasons:**

1. **Backward compatibility** - Old entries with deprecated fields won't break
2. **Data migration safety** - Allows gradual field deprecation without data loss
3. **Multi-model support** - Different models might reuse the same field IDs
4. **Rollback safety** - If you revert a model definition, indexes are still available

**Impact:**

- Virtual columns accumulate over time (intentional)
- Orphaned columns don't hurt performance (they're just unused indexes)
- Manual cleanup available via `php spark stardust:cleanup-columns` command

**Example scenario:**

```php
// Week 1: Create model with price field
$manager->create(['model_fields' => json_encode([
    ['id' => 'price_01', 'type' => 'number'] // Creates v_price_01_num
])], $userId);

// Week 2: Remove price field from model definition
$manager->update($modelId, ['model_fields' => json_encode([
    // price_01 removed
])], $userId);

// Result: v_price_01_num still exists in database (safe!)
// - Old entries can still be queried
// - You can restore the field definition later
// - No data loss or migration needed

// Optional: Clean up orphaned columns
// php spark stardust:cleanup-columns
```

**When to clean up:**

Run `php spark stardust:cleanup-columns` when:

- You've finalized your model schema and removed deprecated fields
- You want to reclaim disk space from unused indexes
- As part of periodic database maintenance
- After major refactoring of your data models

> 📝 The cleanup command is **optional** and **safe to skip**. Orphaned columns are harmless and don't affect performance. Only run cleanup when you're certain the fields won't be restored.
