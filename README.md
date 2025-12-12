# StarDust

### The EAV-over-JSON Library for CodeIgniter 4

**StarDust** is a powerful library for CodeIgniter 4 that implements an Entity-Attribute-Value (EAV) model using modern JSON storage.

It allows you to build applications with **dynamic data models**—where users or administrators can define new fields at runtime—without altering your database schema. By leveraging MySQL/MariaDB JSON functions, StarDust avoids the performance penalties (the "many-joins" problem) typical of traditional EAV SQL structures.

-----

## 🚀 Features

  * **Dynamic Modeling**: Create new entities (Models) and define their fields on the fly.
  * **Optimized JSON Storage**: Stores attributes in `model_fields` and `fields` JSON columns for flat-table performance.
  * **High-Performance Querying**: Uses pre-compiled SQL to extract JSON properties as virtual table columns (no PHP-side filtering required).
  * **Syntax Processor**: A recursive processor to parse nested data queries within JSON content (ideal for API-driven architectures).
  * **Unified Managers**: dedicated `ModelsManager` and `EntriesManager` services to handle CRUD operations.

-----

## 📋 Requirements

  * **PHP**: 8.1 or later
  * **Framework**: CodeIgniter 4.0+
  * **Database**: Must support JSON functions.
      * MySQL 5.7+
      * MariaDB 10.2+

### Database Schema Compatibility

> ⚠️ **Important:** StarDust relies on a user table structure compatible with **CodeIgniter Shield**.

You have two options:

1.  **Recommended:** Install [CodeIgniter Shield](https://shield.codeigniter.com/).
2.  **Manual:** Manually create a users table that replicates the Shield schema. (StarDust does not require Shield logic, only the table structure).

-----

## 📦 Installation

1.  **Install via Composer:**

    ```bash
    composer require damarbob/stardust
    ```

2.  **Run Migrations:**
    Once the package is installed, run the StarDust migrations to set up the necessary internal tables.

    ```bash
    php spark migrate -n StarDust
    ```

-----

## 💻 Usage

### 1\. Managing Models ( The Blueprint )

Use the `ModelsManager` to define the structure of your data. Think of a "Model" as a virtual database table.

```php
use StarDust\Services\ModelsManager;

// Initialize the manager
$manager = \Config\Services::modelsManager();

// Define the blueprint
$modelData = [
    'name'        => 'Products',
    'slug'        => 'products',
    'description' => 'Main product catalog',
    // Define your dynamic fields structure here
    'model_fields' => json_encode([
        // 'id' is a unique key you assign to identify this field internally
        ['id' => 'price_01', 'label' => 'Price', 'type' => 'number'],
        ['id' => 'sku_01',   'label' => 'SKU',   'type' => 'text']
    ])
];

// Create the model
$modelId = $manager->create($modelData, $currentUserId);
```

### 2\. Managing Entries ( The Data )

Use the `EntriesManager` to add records to a Model. An "Entry" acts like a row in a table.

```php
use StarDust\Services\EntriesManager;

// Initialize the manager
$entriesManager = \Config\Services::entriesManager();

// Prepare data mapping field IDs to values
$entryData = [
    'model_id' => $modelId, // The ID of the 'Products' model created above
    'fields'   => json_encode([
        ['id' => 'price_01', 'value' => '15000'],    // Mapping value to Price
        ['id' => 'sku_01',   'value' => 'PROD-001']   // Mapping value to SKU
    ])
];

// Save the entry
$entryId = $entriesManager->create($entryData, $currentUserId);
```

### 3\. Direct Searching

You can search through your JSON data efficiently using `whereFields`. This generates optimized SQL JSON extraction queries.

```php
$entriesModel = model('StarDust\Models\EntriesModel');

$results = $entriesModel
    ->whereFields($entriesModel->builder(), [
        // Searches the JSON blob where the field with ID 'sku_01' contains 'PROD-001'
        ['field' => 'sku_01', 'value' => 'PROD-001'] 
    ])
    ->get()
    ->getResultArray();
```

### 4\. Advanced: Syntax Processor

The `SyntaxProcessor` is designed for scenarios where query logic is passed as JSON (e.g., from a frontend API or a stored configuration). It parses placeholders and executes the query.

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

-----

## 📄 License

MIT License.