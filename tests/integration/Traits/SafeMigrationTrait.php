<?php

namespace StarDust\Tests\Integration\Traits;

use CodeIgniter\CodeIgniter;
use Config\Services;
use PHPUnit\Framework\Attributes\AfterClass;

/**
 * Trait SafeMigrationTrait
 *
 * A replacement for CodeIgniter\Test\DatabaseTestTrait that adds retry logic
 * to migration steps to handle Windows file locking issues (Error 168).
 *
 * This trait relies on properties defined in CIUnitTestCase ($migrate, $migrateOnce, etc.)
 * and overrides the setup/teardown flow to inject retry logic.
 */
trait SafeMigrationTrait
{
    // Properties are inherited from CIUnitTestCase, so we do not redefine them to avoid collisions.
    // protected $migrate;
    // protected $migrateOnce;
    // protected $seedOnce;
    // protected $refresh;
    // protected $seed;
    // protected $basePath;
    // protected $namespace;
    // protected $DBGroup;
    // protected $db;
    // protected $migrations;
    // protected $seeder;
    // protected $insertCache;

    /**
     * Static tracker for migration status
     */
    private static $doneSafeMigration = false;

    /**
     * Static tracker for seeder status
     */
    private static $doneSafeSeed = false;

    //--------------------------------------------------------------------

    /**
     * Load the helpers and dependencies.
     */
    public function loadDependencies()
    {
        if ($this->db === null) {
            $this->db = \Config\Database::connect($this->DBGroup);
            $this->db->initialize();
        }

        if ($this->migrations === null) {
            // Ensure we use the correct database group
            $config = config('Migrations');
            // @phpstan-ignore-next-line
            $config->dbGroup = $this->DBGroup;

            $this->migrations = Services::migrations($config, $this->db);
            $this->migrations->setSilent(true);
        }

        if ($this->seeder === null) {
            $this->seeder = \Config\Database::seeder($this->DBGroup);
            $this->seeder->setSilent(true);
        }
    }

    /**
     * Sets up the database.
     */
    protected function setUpDatabase()
    {
        $this->loadDependencies();
        $this->setUpMigrate();
        $this->setUpSeed();
    }

    /**
     * Tears down the database.
     */
    protected function tearDownDatabase()
    {
        if (! empty($this->insertCache)) {
            foreach ($this->insertCache as $row) {
                $this->db->table($row[0])->where($row[1])->delete();
            }

            $this->insertCache = [];
        }
    }

    /**
     * Prepares the database for each test, ensuring migrations are run.
     * 
     * MODIFIED: Added retry logic integration via runMigrateRetry().
     */
    protected function setUpMigrate()
    {
        if ($this->migrateOnce === false || self::$doneSafeMigration === false) {
            if ($this->refresh === true) {
                // Determine if we need to regress?
                // Standard logic: if refresh is true, we regress.
                // But for migrateOnce=true, we only refresh on the FIRST run (when doneSafeMigration is false).
                // If migrateOnce=false, we refresh every time.
                
                $this->regressDatabase();
                
                // Reset Fabricator counts if available
                if (class_exists(\CodeIgniter\Test\Fabricator::class)) {
                    \CodeIgniter\Test\Fabricator::resetCounts();
                }
            }

            $this->runMigrateRetry();
        }
    }

    /**
     * Runs the migrations with retry logic.
     */
    protected function runMigrateRetry()
    {
        if (! $this->migrate) {
            return;
        }

        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                // Ensure connections are reset to avoid stale handles on retry
                if ($attempts > 0) {
                     $this->db->close();
                     $this->db->connect();
                }

                $this->migrateDatabase();
                
                // If successful
                if ($this->migrateOnce) {
                    self::$doneSafeMigration = true;
                }
                return;

            } catch (\Throwable $e) {
                $attempts++;
                if ($attempts >= $maxAttempts) {
                    // Log error or rethrow
                    throw $e;
                }
                sleep(1);
            }
        }
    }

    /**
     * Actual migration logic.
     */
    protected function migrateDatabase()
    {
        $namespaces = is_array($this->namespace) ? $this->namespace : [$this->namespace];

        if (empty($namespaces)) {
             $this->migrations->setNamespace(null);
             $this->migrations->latest('tests');
        } else {
            foreach ($namespaces as $namespace) {
                $this->migrations->setNamespace($namespace);
                $this->migrations->latest('tests');
            }
        }
    }

    /**
     * Regress the database (rollback).
     */
    protected function regressDatabase()
    {
        if ($this->migrate === false) {
            return;
        }

        // If no namespace was specified then migrate all
        if (empty($this->namespace)) {
            $this->migrations->setNamespace(null);
            $this->migrations->regress(0, 'tests');
        }
        // Run migrations for each specified namespace
        else {
            $namespaces = is_array($this->namespace) ? $this->namespace : [$this->namespace];

            foreach ($namespaces as $namespace) {
                $this->migrations->setNamespace($namespace);
                $this->migrations->regress(0, 'tests');
            }
        }
    }

    /**
     * Seeds the database.
     */
    protected function setUpSeed()
    {
        if ($this->seedOnce === false || self::$doneSafeSeed === false) {
            $this->runSeeds();
            self::$doneSafeSeed = true;
        }
    }

    /**
     * Run the seeds.
     */
    protected function runSeeds()
    {
        if (! empty($this->seed)) {
            $seeds = is_array($this->seed) ? $this->seed : [$this->seed];

            foreach ($seeds as $seed) {
                $this->seeder->call($seed);
            }
        }
    }

    /**
     * Reset static migration/seeder counts.
     */
    #[AfterClass]
    public static function resetMigrationSeedCount(): void
    {
        self::$doneSafeMigration = false;
        self::$doneSafeSeed      = false;
    }
}
