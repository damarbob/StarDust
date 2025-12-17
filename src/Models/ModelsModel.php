<?php

namespace StarDust\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

class ModelsModel extends Model
{
    protected $table = 'models';           // The table name
    protected $primaryKey = 'id';          // Primary key of the table
    protected $allowedFields = ['creator_id', 'deleter_id', 'created_at', 'updated_at', 'deleted_at', 'current_model_data_id']; // Fields that can be inserted/updated
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
     * Override the builder method to return the custom ModelsBuilder.
     *
     * @param string|null $table
     *
     * @return \StarDust\Database\ModelsBuilder
     */
    public function builder(?string $table = null)
    {
        // If the builder is already an instance of ModelsBuilder and we are using the default table, return it.
        if ($this->builder instanceof \StarDust\Database\ModelsBuilder && empty($table)) {
            return $this->builder;
        }

        // Ensure the database connection is initialized
        if (empty($this->db)) {
            $this->db = \Config\Database::connect($this->DBGroup);
        }

        // Use the default table if none is provided
        $table = empty($table) ? $this->table : $table;

        // Create the custom builder
        $builder = new \StarDust\Database\ModelsBuilder($table, $this->db);

        // Cache the builder if it's for the default table
        if (empty($table) || $table === $this->table) {
            $this->builder = $builder;
        }

        return $builder;
    }

    /**
     * Get the StarDust Custom Builder instance.
     * @param bool $onlyDeleted Whether to load the 'deleted' query version.
     * @return \StarDust\Database\ModelsBuilder
     */
    public function stardust(bool $onlyDeleted = false): \StarDust\Database\ModelsBuilder
    {

        $filename = $onlyDeleted ? 'ModelsModelGetDeleted' : 'ModelsModelGet';
        $filepath = locate_query_file($filename);
        $sql      = file_get_contents($filepath);

        // Subquery as the "Table Name"
        $tableName = "($sql) as sub";

        // Pass the table name and the current DB connection to the parent constructor
        return new \StarDust\Database\ModelsBuilder($tableName, $this->db);
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

        // Locate the file using the namespace package
        $filepath = locate_query_file('ModelsModelGet');

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
     * @deprecated Since version 0.2.0-alpha
     * @return BaseBuilder
     * @throws \Exception if the SQL file is not found.
     */
    public function getDeletedCustomBuilder(): BaseBuilder
    {

        // Locate the file using the Namespace package
        $filepath = locate_query_file('ModelsModelGetDeleted');

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
