<?php

namespace StarDust\Models;


use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;
use Config\Database;
use StarDust\Database\EntriesBuilder;

final class EntriesModel extends Model
{
    protected $table = 'entries';           // The table name
    protected $primaryKey = 'id';          // Primary key of the table
    protected $allowedFields = ['model_id', 'creator_id', 'deleter_id', 'created_at', 'updated_at', 'deleted_at', 'current_entry_data_id']; // Fields that can be inserted/updated
    protected $returnType = 'array';       // Return results as arrays
    protected $useTimestamps = true;
    protected $useSoftDeletes = true;

    /**
     * Initializes the model.
     * Use this instead of __construct() to ensure the model is fully set up.
     */
    protected function initialize()
    {
        // Load the internal helper
        helper('StarDust\stardust_internal');
    }

    /**
     * Override the builder method to return the custom EntriesBuilder.
     *
     * @param string|null $table
     *
     * @return EntriesBuilder
     */
    public function builder(?string $table = null)
    {
        // If the builder is already an instance of EntriesBuilder and we are using the default table, return it.
        if ($this->builder instanceof EntriesBuilder && empty($table)) {
            return $this->builder;
        }

        // Ensure the database connection is initialized
        if (empty($this->db)) {
            $this->db = Database::connect($this->DBGroup);
        }

        // Use the default table if none is provided
        $table = empty($table) ? $this->table : $table;

        // Create the custom builder
        $builder = new EntriesBuilder($table, $this->db);

        // Cache the builder if it's for the default table
        if (empty($table) || $table === $this->table) {
            $this->builder = $builder;
        }

        return $builder;
    }

    /**
     * Get the StarDust Custom Builder instance.
     * @param bool $onlyDeleted Whether to load the 'deleted' query version.
     * @return EntriesBuilder
     */
    public function stardust(bool $onlyDeleted = false): EntriesBuilder
    {
        // We explicitly instantiate a new Builder here instead of using $this->builder
        // to ensure a fresh, isolated query state. Using the shared builder instance
        // could lead to query contamination if previous conditions weren't cleared,
        // or duplication of joins/selects if this method were called multiple times.
        $builder = new EntriesBuilder($this->table, $this->db);

        $builder->default();

        if ($onlyDeleted) {
            $builder->whereDeleted();
        } else {
            $builder->whereActive();
        }

        return $builder;
    }

    /*
     * Legacy methods below
     */

    /**
     * Load the SQL query from an external file and return a BaseBuilder.
     *
     * @deprecated Since version 0.2.0-alpha. Will be removed in v0.3.0.
     * @return BaseBuilder
     * @throws \Exception if the SQL file is not found.
     */
    public function getCustomBuilder(): BaseBuilder
    {

        // Locate the file using the Namespace package
        $filepath = locate_query_file('EntriesModelGet');

        // Read the SQL content.
        $sql = file_get_contents($filepath);

        /*
         * Wrap the loaded SQL as a subquery.
         * The idea is to use the subquery in the FROM clause.
         *
         * Note: Make sure your SQL query at Queries/my_query.sql does not include
         * any trailing semicolon, since it will be embedded as a subquery.
         */
        $builder = $this->db->table("($sql) as sub");

        return $builder;
    }

    /**
     * Load the SQL query from an external file and return a BaseBuilder.
     *
     * @deprecated Since version 0.2.0-alpha. Will be removed in v0.3.0.
     * @return BaseBuilder
     * @throws \Exception if the SQL file is not found.
     */
    public function getDeletedCustomBuilder(): BaseBuilder
    {

        // Locate the file using the Namespace package
        $filepath = locate_query_file('EntriesModelGetDeleted');

        // Read the SQL content.
        $sql = file_get_contents($filepath);

        /*
         * Wrap the loaded SQL as a subquery.
         * The idea is to use the subquery in the FROM clause.
         *
         * Note: Make sure your SQL query at Queries/my_query.sql does not include
         * any trailing semicolon, since it will be embedded as a subquery.
         */
        $builder = $this->db->table("($sql) as sub");

        return $builder;
    }

    /**
     * Adds a grouped JSON search condition to a BaseBuilder instance.
     *
     * This method cycles through each condition in the provided array and appends a raw
     * SQL WHERE clause to the query builder. Each condition is expected to have these keys:
     * - 'field': The JSON key within the "fields" column to search.
     * - 'value': The value to match, case-insensitive.
     *
     * The SQL generated extracts a JSON field value from the "fields" column using MySQL's
     * JSON functions. It converts the extracted value to lowercase and then searches for a
     * partial match using LIKE.
     *
     * Example usage:
     * <code>
     *   $builder = $this->myModel->getCustomBuilder();
     *   $builder = $this->whereFields($builder, [
     *       ['field' => 'name', 'value' => 'john'],
     *       ['field' => 'email', 'value' => 'example.com']
     *   ]);
     *   $result = $builder->get()->getResult();
     * </code>
     *
     * @deprecated Since version 0.2.0-alpha. Will be removed in v0.3.0.
     * @param BaseBuilder $builder The query builder instance.
     * @param array $conditions An array of associative arrays with keys 'field' and 'value'.
     *
     * @return BaseBuilder The modified query builder instance.
     */
    public function whereFields(BaseBuilder $builder, array $conditions): BaseBuilder
    {
        // Start grouping the conditions to keep them logically together.
        $builder->groupStart();

        foreach ($conditions as $condition) {
            // Pre-calculate values for the condition.
            // Ensure the field is properly escaped if coming from user input.
            $conditionField = $condition['field'];
            $conditionValue = strtolower($condition['value']);

            // Construct the SQL condition.
            // This SQL extracts the JSON value from the 'fields' column for the supplied field,
            // converts it to lowercase and applies a LIKE search.
            $sql = <<<SQL
                LOWER(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(
                            fields,
                            CONCAT(
                                '$[',
                                SUBSTRING_INDEX(
                                    SUBSTRING_INDEX(
                                        JSON_SEARCH(fields, 'one', '{$conditionField}', NULL, '$[*].id'),
                                        '[',
                                        -1
                                    ),
                                    ']',
                                    1
                                ),
                                '].value'
                            )
                        )
                    )
                ) LIKE '%{$conditionValue}%'
                SQL;

            // Append the raw SQL condition.
            // Passing null as the second parameter and false for automatic escaping.
            $builder->where($sql, null, false);
        }

        // End the group of conditions.
        $builder->groupEnd();

        // Return the builder instance for further chaining.
        return $builder;
    }
}
