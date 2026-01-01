<?php

namespace StarDust\Database;

use CodeIgniter\Database\BaseBuilder;

/**
 * Query Builder for entry_data table.
 * 
 * @internal This builder is used internally by EntryDataModel.
 *           For general entry queries, use EntriesModel->stardust() instead.
 */
final class EntryDataBuilder extends BaseBuilder
{
    /**
     * @var \StarDust\Config\StarDust
     */
    protected $config;

    public function __construct($db, $options = null)
    {
        parent::__construct($db, $options);
        $this->config = config('StarDust');
    }
    /**
     * Join with entries table
     *
     * @return self
     */
    public function joinEntries(): self
    {
        return $this->join('entries', 'entry_data.entry_id = entries.id', 'left');
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
        return $this->join($table, "entry_data.creator_id = {$table}.{$id}", 'left');
    }

    /**
     * Join with models table
     * Requires joinEntries() to be called first if starting from entry_data
     *
     * @return self
     */
    public function joinModels(): self
    {
        return $this->join('models', 'entries.model_id = models.id', 'left');
    }

    /**
     * Join with model_data table
     * Requires joinModels() to be called first.
     *
     * @return self
     */
    public function joinModelData(): self
    {
        return $this->join('model_data', 'models.current_model_data_id = model_data.id', 'left');
    }

    /**
     * Apply all standard joins.
     *
     * @return self
     */
    public function joinDefault(): self
    {
        return $this
            ->joinEntries()
            ->joinCreator()
            ->joinModels()
            ->joinModelData();
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
            ->selectEntry()
            ->selectEntryData()
            ->selectModelData()
            ->selectUsers();
    }

    /**
     * Select columns from entries table
     */
    public function selectEntry(): self
    {
        return $this->select([
            'entries.id as entry_id',
        ]);
    }

    /**
     * Select columns from entry_data table
     */
    public function selectEntryData(): self
    {
        return $this->select([
            'entry_data.id',
            'entry_data.fields',
            'entry_data.created_at AS date_created'
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
        return $this->where('entry_data.deleted_at', null);
    }
}
