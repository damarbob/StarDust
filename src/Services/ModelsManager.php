<?php

namespace StarDust\Services;

use StarDust\Libraries\RuntimeIndexer;
use StarDust\Models\EntriesModel;
use StarDust\Models\EntryDataModel;
use StarDust\Models\ModelsModel;
use StarDust\Models\ModelDataModel;
use StarDust\Database\ModelsBuilder;

/**
 * Service class for managing Models and their associated data.
 */
class ModelsManager
{
    protected ModelsModel $modelsModel;
    protected ModelDataModel $modelDataModel;
    protected EntriesModel $entriesModel;
    protected EntryDataModel $entryDataModel;
    protected RuntimeIndexer $runtimeIndexer;
    protected static $instance;

    /**
     * Get the singleton instance of the ModelsManager.
     *
     * @return static
     */
    public static function getInstance()
    {
        if (is_null(static::$instance)) {
            $modelsModel = model('StarDust\Models\ModelsModel');
            $modelDataModel = model('StarDust\Models\ModelDataModel');
            $entriesModel = model('StarDust\Models\EntriesModel');
            $entryDataModel = model('StarDust\Models\EntryDataModel');
            $runtimeIndexer = service('runtimeIndexer');
            static::$instance = new static(
                $modelsModel,
                $modelDataModel,
                $entriesModel,
                $entryDataModel,
                $runtimeIndexer
            );
        }
        return static::$instance;
    }

    /**
     * Constructor.
     *
     * @param ModelsModel $modelsModel
     * @param ModelDataModel $modelDataModel
     * @param EntriesModel $entriesModel
     * @param EntryDataModel $entryDataModel
     * @param RuntimeIndexer $runtimeIndexer
     */
    /**
     * @var StarDust\Config\StarDust
     */
    protected $config;


    public function __construct(
        ModelsModel $modelsModel,
        ModelDataModel $modelDataModel,
        EntriesModel $entriesModel,
        EntryDataModel $entryDataModel,
        RuntimeIndexer $runtimeIndexer
    ) {
        $this->modelsModel = $modelsModel;
        $this->modelDataModel = $modelDataModel;
        $this->entriesModel = $entriesModel;
        $this->entryDataModel = $entryDataModel;
        $this->runtimeIndexer = $runtimeIndexer;
        $this->config = config('StarDust');
    }

    /**
     * Retrieve all active models.
     * 
     * @deprecated Use query()->get()->getResultArray() instead to avoid memory issues with large datasets.
     *
     * @return array
     */
    public function get(): array
    {
        return $this->modelsModel->stardust()->get()->getResultArray();
    }

    /**
     * Get the query builder for active models.
     * 
     * @return ModelsBuilder
     */

    /**
     * Get the query builder for active models.
     * 
     * @return ModelsBuilder
     */
    public function query(): ModelsBuilder
    {
        return $this->modelsModel->stardust();
    }

    /**
     * Paginate active models.
     *
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public function paginate(int $page = 1, int $perPage = 20): array
    {
        return $this->query()
            ->orderBy('created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();
    }


    /**
     * Retrieve all deleted models.
     * 
     * @deprecated Use queryDeleted()->get()->getResultArray() instead.
     *
     * @return array
     */
    public function getDeleted(): array
    {
        return $this->modelsModel->stardust(true)->get()->getResultArray();
    }

    /**
     * Get the query builder for deleted models.
     * 
     * @return ModelsBuilder
     */
    public function queryDeleted(): ModelsBuilder
    {
        return $this->modelsModel->stardust(true);
    }

    /**
     * Count total active models.
     *
     * @return int|string
     */
    public function count(): int|string
    {
        return $this->modelsModel->stardust()->countAllResults();
    }

    /**
     * Count total deleted models.
     *
     * @return int|string
     */
    public function countDeleted(): int|string
    {
        return $this->modelsModel->stardust(true)->countAllResults();
    }

    /**
     * Find a specific active model by its ID.
     *
     * @param int $id
     * @return array|false
     */
    public function find(int $id): array|false
    {
        $modelResult = $this->modelsModel->stardust()->where('models.id', $id)->get()->getResultArray();

        if (empty($modelResult)) {
            return false;
        }

        return $modelResult[0];
    }

    /**
     * Find multiple active models by their IDs.
     *
     * @param array $ids
     * @return array|false
     */
    public function findModels(array $ids): array|false
    {
        $modelResult = $this->modelsModel->stardust()->whereIn('models.id', $ids)->get()->getResultArray();

        if (empty($modelResult)) {
            return false;
        }

        return $modelResult;
    }

    /**
     * Find a specific deleted model by its ID.
     *
     * @param int $id
     * @return array|false
     */
    public function findDeleted(int $id): array|false
    {
        $modelResult = $this->modelsModel->stardust(true)->where('models.id', $id)->get()->getResultArray();

        if (empty($modelResult)) {
            return false;
        }

        return $modelResult[0];
    }

    /**
     * Find multiple deleted models by their IDs.
     *
     * @param array $ids
     * @return array|false
     */
    public function findDeletedModels(array $ids): array|false
    {
        $modelResult = $this->modelsModel->stardust(true)->whereIn('models.id', $ids)->get()->getResultArray();

        if (empty($modelResult)) {
            return false;
        }

        return $modelResult;
    }

    /**
     * Create a new model and its data.
     *
     * Virtual columns and indexes are automatically generated for fields defined in $data['fields'].
     * If async indexing is enabled, this is offloaded to a queue.
     *
     * @param array $data
     * @param int $userId
     * @return int The ID of the created model.
     */
    public function create(array $data, int $userId): int
    {
        $this->modelsModel->save(['creator_id' => $userId]);
        $modelId = $this->modelsModel->getInsertID();

        $data['model_id'] = $modelId;
        $data['creator_id'] = $userId;
        $this->modelDataModel->save($data);
        $modelDataId = $this->modelDataModel->getInsertID();

        // Update the current version pointer
        $this->modelsModel->update($modelId, ['current_model_data_id' => $modelDataId]);

        // Sync indexes
        $fields = json_decode($data['fields'], true);
        $this->validateFields($fields); // Validate structure before syncing/saving
        $this->handleIndexSync($fields, $modelId);

        return $modelId;
    }

    /**
     * Update a model and its data.
     *
     * Performs a "Smart Update" by merging $data with the current version.
     * Virtual columns and indexes are automatically synchronized for fields defined in $data['fields'].
     * If async indexing is enabled, this is offloaded to a queue.
     *
     * @param int $modelId
     * @param array $data
     * @param int $userId
     * @return void
     */
    public function update(int $modelId, array $data, int $userId): void
    {
        // 1. Fetch current model to get pointer
        $model = $this->modelsModel->find($modelId);
        if (!$model) {
            throw new \RuntimeException("Model not found with ID $modelId");
        }

        // 2. Fetch current data to merge with
        $currentData = [];
        if (!empty($model['current_model_data_id'])) {
            $found = $this->modelDataModel->find($model['current_model_data_id']);
            if ($found) {
                $currentData = $found;
            }
        }

        // 3. Clean up metadata from current data to avoid pollution
        unset(
            $currentData['id'],
            $currentData['created_at'],
            $currentData['updated_at'],
            $currentData['deleted_at'],
            $currentData['creator_id'],
            $currentData['model_id'] // We set this explicitly later
        );

        // 4. Merge: New data overwrites old data
        $mergedData = array_merge($currentData, $data);

        // 5. Save as new version
        $mergedData['model_id'] = $modelId;
        $mergedData['creator_id'] = $userId;

        // Ensure we are inserting a NEW record
        $this->modelDataModel->insert($mergedData);
        $modelDataId = $this->modelDataModel->getInsertID();

        // Update the current version pointer
        $this->modelsModel->update($modelId, ['current_model_data_id' => $modelDataId]);

        // Sync indexes (Check merged data for fields)
        if (isset($mergedData['fields'])) {
            $fields = json_decode($mergedData['fields'], true);
            if (is_array($fields)) {
                $this->validateFields($fields); // Validate structure
                $this->handleIndexSync($fields, $modelId);
            }
        }
    }

    /**
     * Validates the structure of the fields array.
     *
     * @param array $fields
     * @throws \InvalidArgumentException
     */
    protected function validateFields(array $fields): void
    {
        foreach ($fields as $field) {
            if (!isset($field['id']) || !isset($field['type'])) {
                throw new \InvalidArgumentException(
                    "Invalid field definition. Each field must have an 'id' and 'type'."
                );
            }
        }
    }

    /**
     * Handles index synchronization either synchronously or asynchronously based on config.
     *
     * @param array $fields
     * @param int $modelId
     * @return void
     */
    protected function handleIndexSync(array $fields, int $modelId)
    {
        // 1. Check Config
        if ($this->config->asyncIndexing === false) {
            // Default: Synchronous (Blocking)
            $this->runtimeIndexer->syncIndexes($fields);
            return;
        }

        // 2. Check Dependency
        if (!service('queue')) {
            throw new \RuntimeException(
                "Async Indexing is enabled but 'codeigniter4/queue' is not installed. " .
                    "Please run: composer require codeigniter4/queue"
            );
        }

        // 3. Push to Queue
        service('queue')->push(
            $this->config->queueName,
            'StarDust\Jobs\SyncIndexerJob',
            ['modelDefinition' => $fields, 'modelId' => $modelId]
        );
    }

    /**
     * Update specific fields for multiple models.
     *
     * @param array $ids
     * @param array $data
     * @return void
     */
    public function updateModels(array $ids, array $data): void
    {
        $this->modelsModel
            ->whereIn('id', $ids)
            ->set($data)
            ->update();
    }

    /**
     * Update specific fields for multiple model data records.
     *
     * @param array $modelIds
     * @param array $data
     * @return void
     */
    public function updateData(array $modelIds, array $data): void
    {
        $this->modelDataModel
            ->whereIn('model_id', $modelIds)
            ->set($data)
            ->update();
    }

    /**
     * Soft delete models and their associated entries.
     *
     * @param array $ids
     * @param int $deleterId
     * @return void
     */
    public function deleteModels(array $ids, int $deleterId): void
    {
        // Update models and data
        $this->updateModels($ids, ['deleter_id' => $deleterId]);
        $this->updateData($ids, ['deleter_id' => $deleterId]);

        $this->modelDataModel->whereIn('model_id', $ids)->delete();

        $entryIds = array_column(
            $this->entriesModel->select('id')->whereIn('model_id', $ids)->findAll(),
            'id'
        );

        if (!empty($entryIds)) {
            $this->entryDataModel->whereIn('entry_id', $entryIds)
                ->set(['deleter_id' => $deleterId])->update();
            $this->entryDataModel->whereIn('entry_id', $entryIds)->delete();

            $this->entriesModel->whereIn('model_id', $ids)
                ->set(['deleter_id' => $deleterId])->update();
            $this->entriesModel->whereIn('model_id', $ids)->delete();
        }

        $this->modelsModel->delete($ids);
    }

    /**
     * Permanently remove all soft-deleted models and their associated entries.
     *
     * @return void
     */
    public function purgeDeleted(?int $limit = null): int
    {
        $limit = $limit ?? config('StarDust')->purgeLimit ?? 100;

        // 1. Fetch only purgeable models (No existing entries, even deleted ones)
        // Solves N+1 Query Issue
        // Select models.id WHERE entries.id IS NULL
        $purgeableIds = array_column(
            $this->modelsModel
                ->select('models.id')
                ->onlyDeleted()
                ->join('entries', 'entries.model_id = models.id', 'left')
                ->where('entries.id', null)
                ->findAll($limit),
            'id'
        );

        if (empty($purgeableIds)) {
            return 0;
        }

        $db = $this->modelsModel->db;
        $db->transStart();

        try {
            // Delete associated model data
            $this->modelDataModel->whereIn('model_id', $purgeableIds)->delete(purge: true);

            // Delete the models themselves
            $this->modelsModel->delete($purgeableIds, purge: true);

            $db->transComplete();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'PurgeDeletedJob (Models) Failed: ' . $e->getMessage());
            throw $e;
        }

        return count($purgeableIds);
    }

    /**
     * Count deleted models that are ready to be purged (no child entries).
     *
     * @return int
     */
    public function countPurgeableDeleted(): int
    {
        return $this->modelsModel
            ->select('models.id')
            ->onlyDeleted()
            ->join('entries', 'entries.model_id = models.id', 'left')
            ->where('entries.id', null)
            ->countAllResults();
    }

    /**
     * Restore soft-deleted models and their associated entries.
     *
     * @param array $ids
     * @return void
     */
    public function restore(array $ids): void
    {
        $this->modelDataModel->withDeleted()
            ->whereIn('model_id', $ids)->set(['deleted_at' => null])->update();
        $this->modelsModel->withDeleted()
            ->whereIn('id', $ids)->set(['deleted_at' => null])->update();

        $entryIds = array_column(
            $this->entriesModel->withDeleted()->whereIn('model_id', $ids)->findAll(),
            'id'
        );

        if (!empty($entryIds)) {
            $this->entryDataModel->withDeleted()
                ->whereIn('entry_id', $entryIds)->set(['deleted_at' => null])->update();
            $this->entriesModel->withDeleted()
                ->whereIn('id', $entryIds)->set(['deleted_at' => null])->update();
        }
    }
}
