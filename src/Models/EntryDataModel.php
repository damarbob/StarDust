<?php

namespace StarDust\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

class EntryDataModel extends Model
{
    protected $table = 'entry_data';           // The table name
    protected $primaryKey = 'id';          // Primary key of the table
    protected $allowedFields = ['entry_id', 'name', 'fields', 'creator_id', 'deleter_id', 'created_at', 'updated_at', 'deleted_at']; // Fields that can be inserted/updated
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
     * Override the builder method to return the custom EntryDataBuilder.
     *
     * @param string|null $table
     *
     * @return \StarDust\Database\EntryDataBuilder
     */
    public function builder(?string $table = null)
    {
        // If the builder is already an instance of EntryDataBuilder and we are using the default table, return it.
        if ($this->builder instanceof \StarDust\Database\EntryDataBuilder && empty($table)) {
            return $this->builder;
        }

        // Ensure the database connection is initialized
        if (empty($this->db)) {
            $this->db = \Config\Database::connect($this->DBGroup);
        }

        // Use the default table if none is provided
        $table = empty($table) ? $this->table : $table;

        // Create the custom builder
        $builder = new \StarDust\Database\EntryDataBuilder($table, $this->db);

        // Cache the builder if it's for the default table
        if (empty($table) || $table === $this->table) {
            $this->builder = $builder;
        }

        return $builder;
    }

    /**
     * Get the StarDust Custom Builder instance.
     * @return \StarDust\Database\EntryDataBuilder
     */
    public function stardust(): \StarDust\Database\EntryDataBuilder
    {

        $filepath = locate_query_file('EntryDataModelGet');
        $sql      = file_get_contents($filepath);

        // Subquery as the "Table Name"
        $tableName = "($sql) as sub";

        // Pass the table name and the current DB connection to the parent constructor
        return new \StarDust\Database\EntryDataBuilder($tableName, $this->db);
    }

    /**
     * Load the SQL query from an external file and return a BaseBuilder.
     *
     * @deprecated Since version 0.2.0-alpha
     * @return BaseBuilder
     * @throws \Exception if the SQL file is not found.
     */
    public function getCustomBuilder(): BaseBuilder
    {

        // Locate the file using the Namespace package
        $filepath = locate_query_file('EntryDataModelGet');

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
}
