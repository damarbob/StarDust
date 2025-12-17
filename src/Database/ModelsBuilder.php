<?php

namespace StarDust\Database;

use CodeIgniter\Database\BaseBuilder;

final class ModelsBuilder extends BaseBuilder
{
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
        return $this->join('users', 'models.creator_id = users.id', 'left');
    }

    /**
     * Join with users table as editor
     * Requires joinModelData() to be called first.
     *
     * @return self
     */
    public function joinEditor(): self
    {
        return $this->join('users as editors', 'model_data.creator_id = editors.id', 'left');
    }

    /**
     * Join with users table as deleter
     *
     * @return self
     */
    public function joinDeleter(): self
    {
        return $this->join('users as deleters', 'models.deleter_id = deleters.id', 'left');
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
        return $this->select([
            'users.username AS created_by',
            'editors.username AS edited_by',
            'deleters.username AS deleted_by'
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
