<?php

namespace StarDust\Tests\Integration\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use StarDust\Commands\HealthCheck;
use StarDust\Services\ModelsManager;
use StarDust\Services\EntriesManager;

// Test Double to capture output
class TestHealthCheck extends HealthCheck
{
    public $buffer = [];
    public $tables = [];

    // Allow injection but call parent constructor if needed, or just partial mock.
    // Since BaseCommand logic is simple, we can just let it be or inject db via property.
    // But we need to capture output.

    // We can't rely on CLI::write capture easily in all environments, so overriding writeLine is good.
    // But we MUST NOT bypass parent constructor logic if it sets up things (logger, etc).
    // Note: BaseCommand constructor signature is public function __construct(LoggerInterface $logger, CommandRunner $commands)
    // CodeIgniter commands are usually instantiated by the runner.

    // For this test, we can just instantiate it manually and set DB.

    public function __construct()
    {
        parent::__construct(service('logger'), service('commands'));
    }

    protected function writeLine(string $text, ?string $foreground = null)
    {
        $this->buffer[] = $text;
    }

    protected function showTable(array $tbody, array $thead = [])
    {
        $this->tables[] = ['headers' => $thead, 'data' => $tbody];
    }

    // Helper to inject DB
    public function setDB($db)
    {
        $this->db = $db;
    }
}

class HealthCheckTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'StarDust';

    private ModelsManager $modelsManager;
    private EntriesManager $entriesManager;
    private int $testUserId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelsManager = ModelsManager::getInstance();
        $this->entriesManager = EntriesManager::getInstance();
    }

    public function testAnalyzeBlockersIdentifiesStuckModels()
    {
        // 1. Setup Data

        // Model A: Deleted 40 days ago, HAS entries (Stuck)
        $modelA = $this->modelsManager->create(['name' => 'Stuck Model', 'fields' => '{}'], $this->testUserId);
        $this->entriesManager->create(['model_id' => $modelA, 'fields' => '{}'], $this->testUserId);
        $this->modelsManager->deleteModels([$modelA], $this->testUserId);
        // Manually update deleted_at to be old
        $this->db->table('models')->where('id', $modelA)->update(['deleted_at' => date('Y-m-d H:i:s', strtotime('-40 days'))]);

        // Model B: Deleted 40 days ago, NO entries (Clean)
        $modelB = $this->modelsManager->create(['name' => 'Clean Model', 'fields' => '{}'], $this->testUserId);
        $this->modelsManager->deleteModels([$modelB], $this->testUserId);
        $this->db->table('models')->where('id', $modelB)->update(['deleted_at' => date('Y-m-d H:i:s', strtotime('-40 days'))]);

        // Model C: Active, HAS entries (Ignored)
        $modelC = $this->modelsManager->create(['name' => 'Active Model', 'fields' => '{}'], $this->testUserId);
        $this->entriesManager->create(['model_id' => $modelC, 'fields' => '{}'], $this->testUserId);

        // Model D: Deleted 10 days ago, HAS entries (Ignored by filter)
        $modelD = $this->modelsManager->create(['name' => 'Recent Deleted', 'fields' => '{}'], $this->testUserId);
        $this->entriesManager->create(['model_id' => $modelD, 'fields' => '{}'], $this->testUserId);
        $this->modelsManager->deleteModels([$modelD], $this->testUserId);
        $this->db->table('models')->where('id', $modelD)->update(['deleted_at' => date('Y-m-d H:i:s', strtotime('-10 days'))]);

        // 2. Execute Command
        $command = new TestHealthCheck();
        $command->setDB($this->db);
        $command->run(['days' => 30]);

        // 3. Verification

        // Check buffer for "Found 1 stuck models"
        $foundCountMsg = false;
        foreach ($command->buffer as $line) {
            if (str_contains($line, 'Found 1 stuck models')) {
                $foundCountMsg = true;
            }
        }
        $this->assertTrue($foundCountMsg, 'Did not find expected stuck models count message. Buffer: ' . json_encode($command->buffer));

        // Check table contains Model A but NOT Model B, C, D
        $foundModelA = false;
        foreach ($command->tables as $table) {
            foreach ($table['data'] as $row) {
                if ($row[0] == $modelA) $foundModelA = true;
                if ($row[0] == $modelB) $this->fail("Model B (No entries) should not be listed");
                if ($row[0] == $modelC) $this->fail("Model C (Active) should not be listed");
                if ($row[0] == $modelD) $this->fail("Model D (Recent) should not be listed");
            }
        }
        $this->assertTrue($foundModelA, "Model A (Stuck) was not found in the table.");
    }

    public function testNoStuckModels()
    {
        // Setup only clean data
        $model = $this->modelsManager->create(['name' => 'Clean Model', 'fields' => '{}'], $this->testUserId);
        $this->modelsManager->deleteModels([$model], $this->testUserId);
        $this->db->table('models')->where('id', $model)->update(['deleted_at' => date('Y-m-d H:i:s', strtotime('-40 days'))]);

        $command = new TestHealthCheck();
        $command->setDB($this->db);
        $command->run(['days' => 30]);

        // Check buffer
        $foundSuccess = false;
        foreach ($command->buffer as $line) {
            if (str_contains($line, 'No stuck models found')) {
                $foundSuccess = true;
            }
        }
        $this->assertTrue($foundSuccess, 'Did not find success message.');
    }
}
