<?php

namespace StarDust\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;
use Config\Database;
use StarDust\Database\ModelDataBuilder;

class ModelDataModel extends Model
{
    protected $table = 'model_data'; // The table name
    protected $primaryKey = 'id'; // Primary key of the table
    protected $allowedFields = ['model_id', 'name', 'fields', 'group', 'user_groups', 'icon', 'creator_id', 'deleter_id', 'created_at', 'updated_at', 'deleted_at']; // Fields that can be inserted/updated
    protected $returnType = 'array'; // Return results as arrays
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
     * Override the builder method to return the custom ModelDataBuilder.
     *
     * @param string|null $table
     *
     * @return ModelDataBuilder
     */
    public function builder(?string $table = null)
    {
        // If the builder is already an instance of ModelDataBuilder and we are using the default table, return it.
        if ($this->builder instanceof ModelDataBuilder && empty($table)) {
            return $this->builder;
        }

        // Ensure the database connection is initialized
        if (empty($this->db)) {
            $this->db = Database::connect($this->DBGroup);
        }

        // Use the default table if none is provided
        $table = empty($table) ? $this->table : $table;

        // Create the custom builder
        $builder = new ModelDataBuilder($table, $this->db);

        // Cache the builder if it's for the default table
        if (empty($table) || $table === $this->table) {
            $this->builder = $builder;
        }

        return $builder;
    }

    /**
     * Get the StarDust Custom Builder instance.
     * @return ModelDataBuilder
     */
    public function stardust(): ModelDataBuilder
    {
        // We explicitly instantiate a new Builder here instead of using $this->builder
        // to ensure a fresh, isolated query state. Using the shared builder instance
        // could lead to query contamination if previous conditions weren't cleared,
        // or duplication of joins/selects if this method were called multiple times.
        $builder = new ModelDataBuilder($this->table, $this->db);

        $builder->default()->whereActive();

        return $builder;
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
        $filepath = locate_query_file('ModelDataModelGet');

        // Read the SQL content.
        $sql = file_get_contents($filepath);

        /*
         * Wrap the loaded SQL as a subquery.
         * The idea is to use the subquery in the FROM clause.
         *
         * Note: Make sure your SQL query at Queries/my_query.sql does not include
         * any trailing semicolon, since it will be embedded as a subquery.
         */
        // $builder = $this->db->table(
        //     <<<SQL
        //         ($sql) as sub
        //     SQL
        // );
        $builder = $this->db->table("($sql) as sub");

        return $builder;
    }
}
