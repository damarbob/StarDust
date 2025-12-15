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
     * Get the StarDust Custom Builder instance.
     * @return \StarDust\Database\EntryDataBuilder
     */
    public function stardust(): \StarDust\Database\EntryDataBuilder
    {
        helper('StarDust\stardust_internal');

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
        // Load the internal helper
        helper('StarDust\stardust_internal');

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
