<?php

namespace StarDust\Database;

use CodeIgniter\Database\BaseBuilder;
use StarDust\Database\Traits\LegacyAliasTrait;

/**
 * Query Builder for models table.
 * 
 * @internal This builder is used internally by ModelsModel.
 *           For general model queries, use ModelsModel->stardust() instead.
 */
final class ModelsBuilder extends BaseBuilder
{
    /**
     * @var \StarDust\Config\StarDust
     */
    protected $config;

    use LegacyAliasTrait;

    protected $legacyAliasMapping = [
        'id'            => 'models.id',
        'name'          => 'model_data.name',
        'fields'        => 'model_data.fields',
        'group'         => 'model_data.group',
        'user_groups'   => 'model_data.user_groups',
        'icon'          => 'model_data.icon',
        'created_by'    => 'users.username',
        'edited_by'     => 'editors.username',
        'created_at'    => 'models.created_at',
        'date_modified' => 'model_data.created_at',
        'data_id'       => 'model_data.id',
        'deleted_by'    => 'deleters.username',
        'date_deleted'  => 'models.deleted_at',
    ];

    public function __construct($tableName, $db, ?array $options = null)
    {
        parent::__construct($tableName, $db, $options);
        $this->config = $options['config'] ?? config('StarDust');
    }
    /**
     * Join with model_data table
     *
     * @return self
     */
    public function joinModelData(): self
    {
        return $this->join('model_data', 'models.current_model_data_id = model_data.id', 'left');
    }

    /**
     * Join with users table as creator
     *
     * @return self
     */
    public function joinCreator(): self
    {
        $table = $this->config->usersTable;
        $id = $this->config->usersIdColumn;
        return $this->join($table, "models.creator_id = {$table}.{$id}", 'left');
    }

    /**
     * Join with users table as editor
     * Requires joinModelData() to be called first.
     *
     * @return self
     */
    public function joinEditor(): self
    {
        $table = $this->config->usersTable;
        $id = $this->config->usersIdColumn;
        return $this->join("{$table} as editors", "model_data.creator_id = editors.{$id}", 'left');
    }

    /**
     * Join with users table as deleter
     *
     * @return self
     */
    public function joinDeleter(): self
    {
        $table = $this->config->usersTable;
        $id = $this->config->usersIdColumn;
        return $this->join("{$table} as deleters", "models.deleter_id = deleters.{$id}", 'left');
    }

    /**
     * Apply all standard joins.
     *
     * @return self
     */
    public function joinDefault(): self
    {
        return $this
            ->joinModelData()
            ->joinCreator()
            ->joinEditor()
            ->joinDeleter();
    }

    /**
     * Apply default selection and joins.
     *
     * @return self
     */
    public function default(): self
    {
        return $this->selectDefault()->joinDefault();
    }

    /**
     * Select standard columns.
     */
    public function selectDefault(): self
    {
        return $this
            ->selectModels()
            ->selectModelData()
            ->selectUsers();
    }

    /**
     * Select columns from models table
     */
    public function selectModels(): self
    {
        return $this->select([
            'models.id',
            'models.current_model_data_id',
            'models.created_at',
            'models.deleted_at AS date_deleted'
        ]);
    }

    /**
     * Select columns from model_data table
     */
    public function selectModelData(): self
    {
        return $this->select([
            'model_data.name',
            'model_data.fields',
            'model_data.group',
            'model_data.user_groups',
            'model_data.icon',
            'model_data.created_at AS date_modified',
            'model_data.id as data_id'
        ]);
    }

    /**
     * Select columns from users table
     */
    public function selectUsers(): self
    {
        $table = $this->config->usersTable;
        $username = $this->config->usersUsernameColumn;

        return $this->select([
            "{$table}.{$username} AS created_by",
            "editors.{$username} AS edited_by",
            "deleters.{$username} AS deleted_by"
        ]);
    }

    /**
     * Filter for active entries (not deleted).
     */
    public function whereActive(): self
    {
        return $this->where('models.deleted_at', null);
    }

    /**
     * Filter for deleted entries.
     */
    public function whereDeleted(): self
    {
        return $this->where('models.deleted_at IS NOT NULL', null, false);
    }
}
