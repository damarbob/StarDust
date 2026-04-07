<?php

namespace StarDust\Database;

use CodeIgniter\Database\BaseBuilder;
use StarDust\Database\Traits\LegacyAliasTrait;

/**
 * Query Builder for model_data table.
 * 
 * @internal This builder is used internally by ModelDataModel.
 *           For general model queries, use ModelsModel->stardust() instead.
 */
final class ModelDataBuilder extends BaseBuilder
{
    /**
     * @var \StarDust\Config\StarDust
     */
    protected $config;

    use LegacyAliasTrait;

    protected $legacyAliasMapping = [
        'data_id'       => 'model_data.id',
        'id'            => 'models.id',
        'name'          => 'model_data.name',
        'fields'        => 'model_data.fields',
        'icon'          => 'model_data.icon',
        'created_by'    => 'users.username',
        'date_created'  => 'model_data.created_at',
    ];

    public function __construct($tableName, $db, ?array $options = null)
    {
        parent::__construct($tableName, $db, $options);
        $this->config = $options['config'] ?? config('StarDust');
    }
    /**
     * Join with models table
     *
     * @return self
     */
    public function joinModels(): self
    {
        return $this->join('models', 'model_data.model_id = models.id', 'left');
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
        return $this->join($table, "model_data.creator_id = {$table}.{$id}", 'left');
    }

    /**
     * Apply all standard joins.
     *
     * @return self
     */
    public function joinDefault(): self
    {
        return $this
            ->joinModels()
            ->joinCreator();
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
            ->selectModelData()
            ->selectModels()
            ->selectUsers();
    }

    /**
     * Select columns from model_data table
     */
    public function selectModelData(): self
    {
        return $this->select([
            'model_data.id as data_id',
            'model_data.name',
            'model_data.fields',
            'model_data.icon',
            'model_data.created_at AS date_created'
        ]);
    }

    /**
     * Select columns from models table
     */
    public function selectModels(): self
    {
        return $this->select([
            'models.id',
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
        ]);
    }

    /**
     * Filter for active entries (not deleted).
     */
    public function whereActive(): self
    {
        return $this->where('model_data.deleted_at', null);
    }

    /**
     * Order by default (data_id DESC)
     */
    public function orderByDefault(): self
    {
        return $this->orderBy('data_id', 'DESC');
    }
}
