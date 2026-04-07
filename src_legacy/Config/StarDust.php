<?php

namespace StarDust\Config;

use CodeIgniter\Config\BaseConfig;

class StarDust extends BaseConfig
{
    /**
     * --------------------------------------------------------------------------
     * Users Table Configuration
     * --------------------------------------------------------------------------
     *
     * Configuration for the users table used for creator/editor/deleter relationships.
     */

    /**
     * The name of the users table.
     * Default: 'users' (CI Shield)
     *
     * @var string
     */
    public $usersTable = 'users';

    /**
     * The primary key of the users table.
     * Default: 'id' (CI Shield)
     *
     * @var string
     */
    public $usersIdColumn = 'id';

    /**
     * The column used to display the username/identifier.
     * Default: 'username' (CI Shield)
     *
     * @var string
     */
    public $usersUsernameColumn = 'username';

    /**
     * --------------------------------------------------------------------------
     * Asynchronous Indexing
     * --------------------------------------------------------------------------
     *
     * If true, StarDust will offload the heavy "Sync Indexes" operation (DDL)
     * to a background queue. This prevents table locking during user requests.
     *
     * @note Requires `codeigniter4/queue` to be installed.
     *       Run: `composer require codeigniter4/queue`
     *
     * @var bool
     */
    public $asyncIndexing = false;

    /**
     * --------------------------------------------------------------------------
     * Queue Name
     * --------------------------------------------------------------------------
     *
     * The name of the queue to push indexing jobs to.
     * Useful if you are running multiple applications on the same queue server.
     *
     * @var string
     */
    public $queueName = 'stardust-indexes';

    /**
     * --------------------------------------------------------------------------
     * Purge Limit
     * --------------------------------------------------------------------------
     *
     * The number of items to purge per batch in the PurgeDeletedJob.
     * Increase this if your server has high memory/timeout limits.
     * Decrease if you encounter OOM or timeout errors.
     *
     * @var int
     */
    public $purgeLimit = 100;

    /**
     * --------------------------------------------------------------------------
     * Worker Path
     * --------------------------------------------------------------------------
     *
     * The URI path for the HTTP-based Queue Worker (for Free Hosting).
     * You should change this to a secret path to prevent unauthorized triggering
     * if you are not using CLI workers.
     *
     * Example: 'stardust/worker/secret-token-123'
     *
     * @var string
     */
    /**
     * --------------------------------------------------------------------------
     * Worker Data Path
     * --------------------------------------------------------------------------
     *
     * The base URI path for the Queue Worker.
     * Default: 'stardust/worker'
     *
     * @var string
     */
    public $workerPath = 'stardust/worker';

    /**
     * --------------------------------------------------------------------------
     * Worker Token (Security)
     * --------------------------------------------------------------------------
     *
     * A secret token required to trigger the HTTP-based Queue Worker.
     * This ensures only authorized requests (e.g. from your Cron job) can run the worker.
     *
     * You should set this in your .env file:
     * StarDust.workerToken = 'your-super-secret-token-here'
     *
     * @var string|null
     */
    public $workerToken = null;
}
