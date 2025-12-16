<?php

namespace StarDust\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use StarDust\Models\EntryDataModel;

class MigrateEntryFields extends BaseCommand
{
    protected $group       = 'StarDust';
    protected $name        = 'stardust:convert-fields';
    protected $description = 'Converts entry_data.fields from array-of-objects to key-value pairs. Required for virtual columns to work (starting from version 0.2.0-alpha).';

    public function run(array $params)
    {
        $model = new EntryDataModel();

        CLI::write("Converting Entry Data...", 'white', 'blue');

        // Fetch all rows. For very large datasets, chunking is recommended, 
        // but for a library tool we'll start simple or use chunk if provided by model.
        // using findAll for now.
        $entries = $model->findAll();
        $total = count($entries);

        CLI::write("Found {$total} entries to process...", 'yellow');

        $updated = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($entries as $entry) {
            $rawFields = $entry['fields'];

            // 1. Decode provided JSON (Model returnType might already decode it if configured, 
            // but usually 'fields' is just a string or array depending on Casts. 
            // Let's assume it might be array if 'json' cast is on, or string if not.
            // checking type:
            $data = is_string($rawFields) ? json_decode($rawFields, true) : $rawFields;

            if (!is_array($data)) {
                $skipped++;
                continue;
            }

            // 2. Check format
            // If it's already associative (key-value), skip
            // A simple check is if array_keys are NOT 0,1,2...
            if (empty($data) || !array_is_list($data)) {
                // It looks like it's already key-value or empty
                $skipped++;
                continue;
            }

            // 3. Convert from Array-of-Objects to KV
            $newFields = [];
            $validConversion = false;

            foreach ($data as $item) {
                // Must be an array/object with 'id' and 'value'
                $item = (array)$item;
                if (isset($item['id']) && array_key_exists('value', $item)) {
                    $newFields[$item['id']] = $item['value'];
                    $validConversion = true;
                }
            }

            if (!$validConversion && !empty($data)) {
                // Format was list, but didn't look like our target old format. 
                // Could be [ "tag1", "tag2" ] or something else.
                // We better skip to be safe.
                CLI::error("Skipping Entry ID {$entry['id']}: Unrecognized list format.");
                $errors++;
                continue;
            }

            // 4. Update
            // We need to write it back. If Model doesn't cast safely, we json_encode.
            // Assuming we pass array back to update() and Model handles it or we pass JSON string.
            // Let's pass array and let Model/Database handle it (if allowedFields set correctly).
            // Actually, to be safe against double-encoding issues if casts aren't set, 
            // manual json_encode is safer if we are unsure of Model's $casts.
            // Let's look at EntryDataModel previously viewed.
            // It didn't show $casts. So it's likely raw string or handled by events.
            // BUT, if it's raw string in DB, we should save as JSON string.

            try {
                $saveData = [
                    'fields' => json_encode($newFields)
                ];
                $model->update($entry['id'], $saveData);
                $updated++;
            } catch (\Throwable $e) {
                CLI::error("Failed to update Entry ID {$entry['id']}: " . $e->getMessage());
                $errors++;
            }

            CLI::showProgress($updated + $skipped + $errors, $total);
        }

        CLI::newLine();
        CLI::write("Data Conversion Complete.", 'green');
        CLI::write("Updated: $updated", 'green');
        CLI::write("Skipped: $skipped", 'yellow');
        CLI::write("Errors:  $errors", 'red');
        CLI::newLine();

        // ---------------------------------------------------------
        // IMPORTANT: Indexes are required
        // ---------------------------------------------------------
        CLI::error("IMPORTANT: You must now run 'php spark stardust:generate-indexes' to generate the required database indexes.");
        CLI::error("Without these indexes, your virtual columns will not work.");
    }
}
