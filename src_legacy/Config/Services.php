<?php

namespace StarDust\Config;

use StarDust\Services\EntriesManager;
use StarDust\Services\ModelsManager;
use StarDust\Libraries\RuntimeIndexer;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */

    /**
     * Returns an instance of the ModelsManager class.
     */
    public static function modelsManager(bool $getShared = true): ModelsManager
    {
        if ($getShared) {
            return self::getSharedInstance('modelsManager');
        }
        return new ModelsManager(
            model('StarDust\Models\ModelsModel'),
            model('StarDust\Models\ModelDataModel'),
            model('StarDust\Models\EntriesModel'),
            model('StarDust\Models\EntryDataModel'),
            service('runtimeIndexer'),
            config('StarDust')
        );
    }

    /**
     * Returns an instance of the EntriesManager class.
     */
    public static function entriesManager(bool $getShared = true): EntriesManager
    {
        if ($getShared) {
            return self::getSharedInstance('entriesManager');
        }
        return new EntriesManager(
            model('StarDust\Models\EntriesModel'),
            model('StarDust\Models\EntryDataModel'),
            config('StarDust')
        );
    }

    /**
     * Returns an instance of the RuntimeIndexer class.
     */
    public static function runtimeIndexer(bool $getShared = true): RuntimeIndexer
    {
        if ($getShared) {
            return self::getSharedInstance('runtimeIndexer');
        }
        return new RuntimeIndexer();
    }
}
