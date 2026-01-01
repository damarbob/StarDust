# StarDust

### Dynamic Fields with Native SQL Speed + Enterprise-grade Dual Versioning

**StarDust** is a high-performance library for CodeIgniter 4 that allows you to add **dynamic data models** to your application without the performance cost usually associated with EAV (Entity-Attribute-Value) or JSON storage.

Unlike other solutions that rely on slow software-side filtering or complex JOINs, StarDust uses a **Runtime Indexer** to automatically generate **MySQL Virtual Columns** and **B-Tree Indexes** for your JSON fields. Plus, every change is automatically versioned—giving you a complete audit trail without extra work.

**The result?** You get the flexibility of NoSQL (add fields on the fly) with the query speed of a native SQL table, plus enterprise-grade change tracking built-in.

---

## 🚀 Features

- **Runtime Indexer**: Automatically maintains virtual columns (`v_price_num`, `v_sku_str`) and indexes for your dynamic fields, ensuring high-performance `WHERE` and `ORDER BY` operations.
- **Dual Versioning**: Complete change history for both models and entries—every update creates a new version while maintaining instant access to current data. Perfect for audit trails, compliance, and data recovery.
- **Dynamic Modeling**: Create new entities (Products, Pages, Tickets) and define their fields in JSON at runtime.
- **Optimized Storage**: Uses a "Flat Table" approach enhanced by JSON columns, avoiding the "EAV Join Hell".
- **Syntax Processor**: A recursive processor to parse nested data queries within JSON content (ideal for API-driven architectures).
- **Unified Managers**: Simple `ModelsManager` and `EntriesManager` services to handle CRUD.

---

## ℹ️ Core Concepts

StarDust revolves around three fundamental concepts:

- **Models** (The Blueprint):
  Think of a **Model** as a dynamic table definition (e.g., "Products", "Tickets"). It defines the structure and settings for a collection of data.

- **Entries** (The Record):
  An **Entry** is a single instance of a Model (e.g., a specific Product). Data is stored logically as JSON but physically optimized for SQL performance.

- **Fields** (The Attribute):
  **Fields** are the data points defined within a Model (e.g., `price`, `sku`). StarDust maps these JSON fields to **Virtual Columns**, giving you the query performance of native SQL columns.

---

## 📋 Requirements

- **PHP**: 8.1 or later
- **Framework**: CodeIgniter 4.0+
- **Database**: Must support JSON and Generated Columns.
  - MySQL 5.7+
  - MariaDB 10.2+

### Database Schema Compatibility

StarDust communicates with your `users` table to track who created or modified data.

**Default Behavior:**
StarDust is pre-configured to work with **[CodeIgniter Shield](https://shield.codeigniter.com/)** out of the box.

**Custom / Agnostic Setup:**
You can use **ANY** authentication system or existing `users` table.

**Option 1: ENV File (Easiest)**
Add these to your `.env` file:

```ini
StarDust.usersTable = 'my_custom_users'
StarDust.usersIdColumn = 'user_uuid'
StarDust.usersUsernameColumn = 'display_name'
```

**Option 2: Config File (Recommended for teams)**
Create a file at `app/Config/StarDust.php`:

```php
<?php

namespace Config;

use StarDust\Config\StarDust as BaseStarDust;

class StarDust extends BaseStarDust
{
    public $usersTable          = 'my_custom_users';
    public $usersIdColumn       = 'user_uuid';
    public $usersUsernameColumn = 'display_name';
}
```

**Automatic Polyfill:**
For testing or fresh installations without an auth system, StarDust includes a **Polyfill Migration**. It will automatically create a minimal `users` table **ONLY IF** one does not already exist. This ensures you can get started immediately without setup friction.

---

## 📦 Installation

1.  **Install via Composer:**

    ```bash
    composer require damarbob/stardust
    ```

2.  **Run Migrations:**
    ```bash
    php spark migrate -n StarDust
    ```

---

## 💻 Usage

### 1\. Managing Models ( The Blueprint )

Define your data structure using the `ModelsManager`.

> **Key concept:** When you create or update a model, the **Runtime Indexer** automatically generates virtual columns and indexes for fields in `model_fields`.

```php
use StarDust\Services\ModelsManager;

$manager = service('modelsManager');

$modelData = [
    'name'        => 'Products',
    'slug'        => 'products',
    'description' => 'Main product catalog',
    'model_fields' => json_encode([
        // The 'type' determines the index suffix (_num, _str, _dt)
        ['id' => 'price_01', 'label' => 'Price', 'type' => 'number'], // Creates 'v_price_01_num'
        ['id' => 'sku_01',   'label' => 'SKU',   'type' => 'text']    // Creates 'v_sku_01_str'
    ])
];

$modelId = $manager->create($modelData, $currentUserId);
```

### 2\. Managing Entries ( The Data )

Add records using `EntriesManager`. Data is stored as JSON but mirrored into the virtual columns for speed.

```php
use StarDust\Services\EntriesManager;

$entriesManager = service('entriesManager');

$entryData = [
    'model_id' => $modelId,
    'fields'   => json_encode([
        'price_01' => '15000',
        'sku_01'   => 'PROD-001'
    ])
];

$entriesManager->create($entryData, $currentUserId);
```

### 3\. High-Performance Querying

This is where StarDust shines. Instead of slow JSON searching, you query the auto-generated virtual columns directly.

**Naming Convention:** `v_{field_id}_{suffix}`

| Suffix | Field Types              | SQL Type      |
| :----- | :----------------------- | :------------ |
| `_num` | `number`, `range`        | DECIMAL(20,4) |
| `_dt`  | `date`, `datetime-local` | DATETIME      |
| `_str` | `text`, `select`, etc.   | VARCHAR(191)  |

**Example:**

```php
$entriesModel = model('StarDust\Models\EntriesModel');

$results = $entriesModel->stardust()
    // Native SQL speed! Validated by B-Tree Indexes.
    ->where('v_price_01_num >', 1000)
    ->where('v_sku_01_str', 'PROD-001')
    ->get()
    ->getResultArray();
```

### 4\. Using the Custom Builder

Use the `stardust()` method to query entries with pre-configured JOINs and virtual column support:

```php
$entriesModel->stardust()
    ->where('v_price_01_num >', 1000)
    ->get();
```

> **Note:** See [FAQ](FAQ.md#why-use-stardust-instead-of-standard-model-methods) for why `stardust()` is recommended over standard Model methods.

### 5\. Searching Non-Indexed Fields

For fields not indexed (e.g., `textarea` types), use `likeFields()`:

```php
$entriesModel->stardust()->likeFields([
    ['field' => 'description_01', 'value' => 'fragile']
])->get();
```

> ⚠️ **Performance:** This performs a full table scan. See [FAQ](FAQ.md#how-do-i-search-non-indexed-fields) for details and alternatives.

### 6\. Accessing Version History

Every update to a model or entry creates a new version. Access the complete history using the data models:

```php
// Get all versions of a model's fields
$modelDataModel = model('StarDust\\Models\\ModelDataModel');
$modelVersions = $modelDataModel->where('model_id', $modelId)
    ->orderBy('created_at', 'DESC')
    ->findAll();

// Get all versions of an entry's data
$entryDataModel = model('StarDust\\Models\\EntryDataModel');
$entryVersions = $entryDataModel->where('entry_id', $entryId)
    ->orderBy('created_at', 'DESC')
    ->findAll();

// Each record includes:
// - fields (JSON): The data at that point in time
// - created_at: When this version was created
// - creator_id: Who made the change
```

> 💡 **Tip:** The `models` and `entries` tables have `current_model_data_id` and `current_entry_data_id` pointers that always reference the latest version for instant access.

### 7\. Advanced: Dynamic JSON Queries

The `SyntaxProcessor` enables API-driven queries where logic is passed as JSON:

```php
$processor = syntax_processor();
$result = $processor->process($jsonRequest);
```

> 💡 See [FAQ - Syntax Processor](FAQ.md#how-do-i-use-the-syntax-processor-for-dynamic-queries) for detailed usage examples.

---

## 🛠️ CLI Tools

### Regenerating Indexes

For repair or regeneration scenarios (not needed for normal usage):

```bash
php spark stardust:generate-indexes
```

> **Note:** Virtual columns are **automatically created** when using `modelsManager->create()` or `->update()`. This command is only needed for database repairs or migrations.

**Additional commands:**

- `stardust:cleanup-columns` - Remove orphaned virtual columns
- `stardust:map-current` - Update version pointers for latest entry data
- `stardust:convert-fields` - Migrate from v0.1.x data format

> 📖 See [FAQ - CLI Commands](FAQ.md#what-cli-commands-are-available-for-maintenance) for detailed usage and when to run each command.

---

## 🧩 Global Helpers

### `syntax_processor()`

A convenience wrapper to get a new instance of the `SyntaxProcessor` library.

```php
// Instead of: $processor = new \StarDust\Libraries\SyntaxProcessor();
$processor = syntax_processor();
$result = $processor->process($jsonRequest);
```

---

## 📚 Documentation

- **[FAQ](FAQ.md)** - Common questions, advanced usage, and troubleshooting
- **[Migration Guide](FAQ.md#how-do-i-upgrade-from-v01x-to-v020)** - Upgrading from v0.1.x to v0.2.0+

---

## 📄 License

MIT License.
