<?php

namespace StarDust\Services;

use StarDust\Models\EntriesModel;
use StarDust\Models\EntryDataModel;
use StarDust\Database\EntriesBuilder;

/**
 * Class EntriesManager
 *
 * Provides methods to manage entries and their associated data.
 * Supports operations such as creating, updating, deleting, purging,
 * and restoring entries and entry data.
 */
class EntriesManager
{
    /**
     * @var EntriesModel
     */
    protected EntriesModel $entriesModel;

    /**
     * @var EntryDataModel
     */
    protected EntryDataModel $entryDataModel;

    /**
     * @var self|null Cached singleton instance.
     * @deprecated Since version 0.3.0. Use Dependency Injection via service('entriesManager') instead.
     */
    protected static ?self $instance = null;

    /**
     * Returns a shared instance of the EntriesManager.
     *
     * @return self
     * @deprecated Since version 0.3.0. Use Dependency Injection via service('entriesManager') instead.
     */
    public static function getInstance(): self
    {
        if (is_null(static::$instance)) {
            $entriesModel = model('StarDust\Models\EntriesModel');
            $entryDataModel = model('StarDust\Models\EntryDataModel');
            $config = config('StarDust');
            static::$instance = new static($entriesModel, $entryDataModel, $config);
        }
        return static::$instance;
    }

    /**
     * Resets the singleton instance. (For testing purposes)
     * @deprecated Since version 0.3.0. Use Services::injectMock() instead.
     */
    public static function resetInstance()
    {
        static::$instance = null;
    }

    /**
     * @var \StarDust\Config\StarDust
     */
    protected $config;

    /**
     * Constructor.
     *
     * @param EntriesModel          $entriesModel
     * @param EntryDataModel        $entryDataModel
     * @param \StarDust\Config\StarDust|null $config
     */
    public function __construct(EntriesModel $entriesModel, EntryDataModel $entryDataModel, ?\StarDust\Config\StarDust $config = null)
    {
        $this->entriesModel = $entriesModel;
        $this->entryDataModel = $entryDataModel;
        $this->config = $config ?? config('StarDust');
    }

    /**
     * Retrieves all entries.
     * 
     * @deprecated Since version 0.2.0-alpha. Will be removed in v0.3.0. Use paginate() instead.
     *
     * @return array The entries as an array.
     */
    public function get(): array
    {
        return $this->entriesModel->stardust()->get()->getResultArray();
    }

    /**
     * Get the query builder for entries.
     * 
     * @return EntriesBuilder
     * @deprecated Since version 0.3.0. The Service Layer should encapsulate queries.
     */
    public function query(): EntriesBuilder
    {
        return $this->entriesModel->stardust();
    }

    /**
     * Retrieves all deleted entries.
     * 
     * @deprecated Since version 0.2.0-alpha. Will be removed in v0.3.0. Use paginate() with deleted=true criteria.
     *
     * @return array The deleted entries as an array.
     */
    public function getDeleted(): array
    {
        return $this->entriesModel->stardust(true)->get()->getResultArray();
    }

    /**
     * Get the query builder for deleted entries.
     * 
     * @return EntriesBuilder
     * @deprecated Since version 0.3.0. The Service Layer should encapsulate queries.
     */
    public function queryDeleted(): EntriesBuilder
    {
        return $this->entriesModel->stardust(true);
    }

    /**
     * Paginates entries based on criteria.
     *
     * @param int $page
     * @param int $perPage
     * @param \StarDust\Data\EntrySearchCriteria|null $criteria
     * @return array
     */
    public function paginate(int $page = 1, int $perPage = 20, ?\StarDust\Data\EntrySearchCriteria $criteria = null): array
    {
        // Determine which builder to start with based on deleted flag
        $builder = ($criteria && $criteria->includeDeleted)
            ? $this->entriesModel->stardust(true)
            : $this->entriesModel->stardust();

        if ($criteria) {
            if ($criteria->hasSearchTerm()) {
                // Entries usually don't have a direct 'name' like models, 
                // typically we search within the JSON data or a related column.
                // Assuming 'slug' or 'id' for now, or delegating to a more complex search.
                // Adjust based on actual schema. FOR NOW matching ModelsManager pattern.
                // Note: entries table often only has id, model_id, etc. 
                // Search logic might need to join entry_data.

                // If strict search implementation is needed, we'd need to know the schema better.
                // Disabling complex text search on generic entries for now unless requested.
            }

            if ($criteria->hasModelId()) {
                $builder->where('entries.model_id', $criteria->modelId);
            }

            if ($criteria->hasIds()) {
                $builder->whereIn('entries.id', $criteria->ids);
            }

            if ($criteria->hasDateFilters()) {
                if ($criteria->createdAfter) {
                    $builder->where('entries.created_at >=', $criteria->createdAfter);
                }
                if ($criteria->createdBefore) {
                    $builder->where('entries.created_at <=', $criteria->createdBefore);
                }
                if ($criteria->updatedBefore) {
                    $builder->where('entries.updated_at <=', $criteria->updatedBefore);
                }
            }

            if ($criteria->hasCustomFilters()) {
                foreach ($criteria->customFilters as $field => $value) {
                    // UX Improvement: Auto-append 'v_' if missing.
                    // This allows 'price_01_num' to work as 'v_price_01_num'.
                    if (!str_starts_with($field, 'v_')) {
                        $field = 'v_' . $field;
                    }

                    // Security: effectively forced to virtual columns.
                    $builder->where($field, $value);
                }
            }
        }

        return $builder
            ->orderBy('entries.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
    }

    /**
     * Counts the total number of entries.
     *
     * @return int|string The number of entries.
     */
    public function count(): int|string
    {
        return $this->entriesModel->stardust()->countAllResults();
    }

    /**
     * Counts the entry data records for a specific entry.
     *
     * @param int $entryId
     *
     * @return int|string The count of entry data records.
     */
    public function countData(int $entryId): int|string
    {
        return $this->entryDataModel
            ->stardust()
            ->where('entry_id', $entryId)
            ->countAllResults();
    }

    /**
     * Counts the total number of deleted entries.
     *
     * @return int|string The number of deleted entries.
     */
    public function countDeleted(): int|string
    {
        return $this->entriesModel->stardust(true)->countAllResults();
    }

    /**
     * Finds a single entry by its ID.
     *
     * @param int $id
     *
     * @return array|false The entry data as an associative array, or false if not found.
     */
    public function find(int $id): array|false
    {
        $result = $this->entriesModel->stardust()
            ->where('entries.id', $id)
            ->get()
            ->getResultArray();
        return empty($result) ? false : $result[0];
    }

    /**
     * Finds multiple entries by their IDs.
     *
     * @param array $ids
     *
     * @return array|false The entries as an array, or false if none found.
     */
    public function findEntries(array $ids): array|false
    {
        $result = $this->entriesModel->stardust()
            ->whereIn('entries.id', $ids)
            ->get()
            ->getResultArray();
        return empty($result) ? false : $result;
    }

    /**
     * Finds a deleted entry by its ID.
     *
     * @param int $id
     *
     * @return array|false The deleted entry data, or false if not found.
     */
    public function findDeleted(int $id): array|false
    {
        $result = $this->entriesModel->stardust(true)
            ->where('entries.id', $id)
            ->get()
            ->getResultArray();
        return empty($result) ? false : $result[0];
    }

    /**
     * Finds multiple deleted entries by their IDs.
     *
     * @param array $ids
     *
     * @return array|false The deleted entries as an array, or false if none found.
     */
    public function findDeletedEntries(array $ids): array|false
    {
        $result = $this->entriesModel->stardust(true)
            ->whereIn('entries.id', $ids)
            ->get()
            ->getResultArray();
        return empty($result) ? false : $result;
    }

    /**
     * Creates a new entry and associated entry data.
     *
     * @param array $data   Entry data.
     * @param int   $userId The ID of the user creating the entry.
     *
     * @return int The newly created entry's ID.
     */
    public function create(array $data, int $userId): int
    {
        $db = $this->entriesModel->db;
        $db->transStart();

        try {
            $this->entriesModel->save([
                'model_id' => $data['model_id'],
                'creator_id' => $userId,
            ]);

            $id = $this->entriesModel->getInsertID();

            // Set the foreign key for entry data.
            $data['entry_id'] = $id;
            $data['creator_id'] = $userId;
            $this->entryDataModel->save($data);
            $entryDataId = $this->entryDataModel->getInsertID();

            // Update the current version pointer
            $this->entriesModel->update($id, ['current_entry_data_id' => $entryDataId]);

            $db->transComplete();

            if ($db->transStatus() === false) {
                $error = $db->error();
                throw new \RuntimeException($error['message'] ?: 'Database transaction failed during create.');
            }
        } catch (\Throwable $e) {
            // If we caught an exception, we should rethrow it.
            throw $e;
        }

        return $id;
    }

    /**
     * Updates entry data for a given entry.
     *
     * @param int   $entryId The entry ID.
     * @param array $data    The data to update.
     * @param int   $userId  The ID of the user performing the update.
     *
     * @return void
     */
    public function update(int $entryId, array $data, int $userId): void
    {
        $db = $this->entriesModel->db;
        $db->transStart();

        try {
            $data['entry_id'] = $entryId;
            $data['creator_id'] = $userId;
            $this->entryDataModel->save($data);
            $entryDataId = $this->entryDataModel->getInsertID();

            // Update the current version pointer
            $this->entriesModel->update($entryId, ['current_entry_data_id' => $entryDataId]);

            $db->transComplete();

            if ($db->transStatus() === false) {
                $error = $db->error();
                throw new \RuntimeException($error['message'] ?: 'Database transaction failed during update.');
            }
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Updates multiple entries.
     *
     * @param array $entryIds The IDs of the entries to update.
     * @param array $data     The data to update.
     *
     * @return void
     */
    public function updateEntries(array $entryIds, array $data): void
    {
        $this->entriesModel->whereIn('id', $entryIds)
            ->set($data)
            ->update();
    }

    /**
     * Updates entry data for multiple entries.
     *
     * @param array $entryIds The IDs of the entries.
     * @param array $data     The data to update.
     *
     * @return void
     */
    public function updateData(array $entryIds, array $data): void
    {
        $this->entryDataModel->whereIn('entry_id', $entryIds)
            ->set($data)
            ->update();
    }

    /**
     * Soft deletes entries and their associated entry data.
     *
     * @param array $ids       The IDs of entries to delete.
     * @param int   $deleterId The ID of the user performing the deletion.
     *
     * @return void
     */
    public function deleteEntries(array $ids, int $deleterId): void
    {
        $db = $this->entriesModel->db;
        $db->transStart();

        try {
            // Update deleter info.
            $this->updateEntries($ids, ['deleter_id' => $deleterId]);
            $this->updateData($ids, ['deleter_id' => $deleterId]);

            // Delete associated entry data.
            $this->entryDataModel->whereIn('entry_id', $ids)->delete();

            // Soft delete entries.
            $this->entriesModel->delete($ids);

            $db->transComplete();

            if ($db->transStatus() === false) {
                $error = $db->error();
                throw new \RuntimeException($error['message'] ?: 'Database transaction failed during deletion.');
            }
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function purgeDeleted(?int $limit = null): int
    {
        $limit = $limit ?? $this->config->purgeLimit ?? 100;

        $ids = array_column(
            $this->entriesModel->select('id')->onlyDeleted()->findAll($limit),
            'id'
        );

        if (empty($ids)) {
            return 0;
        }

        $db = $this->entriesModel->db;
        $db->transStart();

        try {
            // Purge entry data for deleted records.
            $this->entryDataModel->whereIn('entry_id', $ids)->delete(purge: true);

            // Purge deleted entries.
            $this->entriesModel->delete($ids, purge: true);

            $db->transComplete();

            if ($db->transStatus() === false) {
                $error = $db->error();
                throw new \RuntimeException($error['message'] ?: 'Database transaction failed during purge.');
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'PurgeDeletedJob (Entries) Failed: ' . $e->getMessage());
            throw $e;
        }

        return count($ids);
    }

    /**
     * Restores soft-deleted entries and their associated entry data.
     *
     * @param array $ids The entry IDs to restore.
     *
     * @return void
     */
    public function restore(array $ids): void
    {
        $db = $this->entriesModel->db;
        $db->transStart();

        try {
            // Restore entry data by nullifying the deleted_at timestamps.
            $this->entryDataModel->withDeleted()
                ->whereIn('entry_id', $ids)
                ->set(['deleted_at' => null])
                ->update();

            // Restore the entries themselves.
            $this->entriesModel->withDeleted()
                ->whereIn('id', $ids)
                ->set(['deleted_at' => null])
                ->update();

            $db->transComplete();

            if ($db->transStatus() === false) {
                $error = $db->error();
                throw new \RuntimeException($error['message'] ?: 'Database transaction failed during restore.');
            }
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
