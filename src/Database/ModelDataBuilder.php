<?php

namespace StarDust\Database;

use CodeIgniter\Database\BaseBuilder;

/**
 * Query Builder for model_data table.
 * 
 * @internal This builder is used internally by ModelDataModel.
 *           For general model queries, use ModelsModel->stardust() instead.
 */
final class ModelDataBuilder extends BaseBuilder
{
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
        return $this->join('users', 'model_data.creator_id = users.id', 'left');
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
        return $this->select([
            'users.username AS created_by',
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
