<?php

namespace StarDust\Database;

use CodeIgniter\Database\BaseBuilder;

final class EntriesBuilder extends BaseBuilder
{

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
     * The custom chainable method.
     */
    public function likeFields(array $conditions): self
    {
        $this->groupStart();

        foreach ($conditions as $condition) {
            $conditionField = $condition['field'];
            $conditionValue = strtolower($condition['value']);

            $sql = <<<SQL
                LOWER(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(fields, '$."{$conditionField}"')
                    )
                ) LIKE '%{$conditionValue}%'
                SQL;

            $this->where($sql, null, false);
        }

        $this->groupEnd();

        // Return $this (the wrapper) to allow further chaining
        return $this;
    }

    /**
     * Join with entry_data table
     *
     * @return self
     */
    public function joinEntryData(): self
    {
        return $this->join('entry_data', 'entries.current_entry_data_id = entry_data.id', 'left');
    }

    /**
     * Join with models table
     *
     * @return self
     */
    public function joinModel(): self
    {
        return $this->join('models', 'entries.model_id = models.id', 'left');
    }

    /**
     * Join with model_data table
     * Requires joinModel() to be called first.
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
        return $this->join('users', 'entries.creator_id = users.id', 'left');
    }

    /**
     * Join with users table as editor
     * Requires joinEntryData() to be called first.
     *
     * @return self
     */
    public function joinEditor(): self
    {
        return $this->join('users as editors', 'entry_data.creator_id = editors.id', 'left');
    }

    /**
     * Join with users table as deleter
     *
     * @return self
     */
    public function joinDeleter(): self
    {
        return $this->join('users as deleters', 'entries.deleter_id = deleters.id', 'left');
    }

    /**
     * Apply all standard joins for a complete entry record.
     *
     * @return self
     */
    public function joinDefault(): self
    {
        return $this
            ->joinEntryData()
            ->joinModel()
            ->joinModelData()
            ->joinCreator()
            ->joinEditor()
            ->joinDeleter();
    }

    /**
     * Select standard columns (Superset of active and deleted views).
     */
    public function selectDefault(): self
    {
        return $this
            ->selectEntry()
            ->selectModelData()
            ->selectEntryData()
            ->selectUsers();
    }

    /**
     * Select columns from entries table
     */
    public function selectEntry(): self
    {
        return $this->select([
            'entries.id',
            'entries.created_at',
            'entries.deleted_at AS date_deleted'
        ]);
    }

    /**
     * Select columns from model_data table
     */
    public function selectModelData(): self
    {
        return $this->select([
            'model_data.model_id as model_id',
            'model_data.name as model_name',
            'model_data.fields AS model_fields',
            'model_data.user_groups'
        ]);
    }

    /**
     * Select columns from entry_data table
     */
    public function selectEntryData(): self
    {
        return $this->select([
            'entry_data.fields',
            'entry_data.created_at AS date_modified',
            'entry_data.id as data_id'
        ]);
    }

    /**
     * Select columns from users table (creator, editor, deleter)
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
        return $this->where('entries.deleted_at', null);
    }

    /**
     * Filter for deleted entries.
     */
    public function whereDeleted(): self
    {
        return $this->where('entries.deleted_at IS NOT NULL', null, false);
    }
}
