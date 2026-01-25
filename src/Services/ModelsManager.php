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
    public function query(): ModelsBuilder
    {
        return $this->modelsModel->stardust();
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
        $this->handleIndexSync($fields, $modelId);

        return $modelId;
    }

    /**
     * Update a model and its data.
     *
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
        $data['model_id'] = $modelId;
        $data['creator_id'] = $userId;
        $this->modelDataModel->save($data);
        $modelDataId = $this->modelDataModel->getInsertID();

        // Update the current version pointer
        $this->modelsModel->update($modelId, ['current_model_data_id' => $modelDataId]);

        // Sync indexes
        $fields = json_decode($data['fields'], true);
        $this->handleIndexSync($fields, $modelId);
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
    public function purgeDeleted(): void
    {
        $modelIds = array_column(
            $this->modelsModel->select('id')->onlyDeleted()->findAll(),
            'id'
        );

        if (empty($modelIds))
            return;

        $entryIds = array_column(
            $this->entriesModel->select('id')->withDeleted()->whereIn('model_id', $modelIds)->findAll(),
            'id'
        );

        $this->modelDataModel->whereIn('model_id', $modelIds)->delete(purge: true);

        if (!empty($entryIds)) {
            $this->entryDataModel->whereIn('entry_id', $entryIds)->delete(purge: true);
        }

        $this->modelsModel->purgeDeleted();
        $this->entriesModel->purgeDeleted();
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
