<?php

namespace StarDust\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

class ModelsModel extends Model
{
    protected $table = 'models';           // The table name
    protected $primaryKey = 'id';          // Primary key of the table
    protected $allowedFields = ['creator_id', 'deleter_id', 'created_at', 'updated_at', 'deleted_at']; // Fields that can be inserted/updated
    protected $returnType = 'array';       // Return results as arrays
    protected $useTimestamps = true;
    protected $useSoftDeletes = true;

    /**
     * Load the SQL query from an external file and return a BaseBuilder.
     *
     * @return BaseBuilder
     * @throws \Exception if the SQL file is not found.
     */
    public function getCustomBuilder(): BaseBuilder
    {
        // Load the internal helper
        helper('StarDust\stardust_internal');

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
     * @return BaseBuilder
     * @throws \Exception if the SQL file is not found.
     */
    public function getDeletedCustomBuilder(): BaseBuilder
    {
        // Load the internal helper
        helper('StarDust\stardust_internal');

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
