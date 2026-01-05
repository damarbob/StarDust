<?php

namespace StarDust\Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use StarDust\Controllers\QueueWorker;
use Config\Services;

// --- Test Support Classes ---

/**
 * A testable version of QueueWorker that forces the "installed" check to pass.
 */
class TestableQueueWorker extends QueueWorker
{
    protected function isQueueLibraryInstalled(): bool
    {
        return true;
    }
}

/**
 * A simple dummy job class for testing payload processing.
 */
class DummyJob
{
    public static $processedData = [];

    public function __construct(public array $data) {}

    public function process()
    {
        self::$processedData[] = $this->data;
    }
}

/**
 * A mock Job object to be returned by Queue::pop
 */
class MockJob
{
    public $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }
}

// --- Main Test Class ---

class QueueWorkerInstalledTest extends CIUnitTestCase
{
    use ControllerTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        DummyJob::$processedData = []; // Reset static state
    }

    public function testWorkProcessesJobs()
    {
        // 1. Mock the Queue Service
        $mockQueue = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['pop', 'done', 'delete'])
            ->getMock();

        // Expect pop to be called at least once. 
        // We'll return one job, then null to simulate empty queue.
        $mockQueue->expects($this->any())
            ->method('pop')
            ->willReturnOnConsecutiveCalls(
                new MockJob([
                    'job' => DummyJob::class,
                    'data' => ['id' => 123]
                ]),
                null // Stop the loop
            );

        // Expect done (or delete) to be called for the processed job
        // We can just verify method existence logic or use 'done' as primary default in Controller
        // The controller checks method_exists, so we mocked both to be safe, but let's say 'done' is preferred.
        $mockQueue->expects($this->once())->method('done');

        Services::injectMock('queue', $mockQueue);

        // 2. Configure Token
        $config = config('StarDust');
        $config->workerToken = 'secret';

        // 3. Execute
        $result = $this->withConfig($config)
            ->controller(TestableQueueWorker::class)
            ->execute('work', 'secret');

        // 4. Verify
        $this->assertTrue($result->response()->getStatusCode() === 200);
        $this->assertStringContainsString('Processed 1 jobs', $result->response()->getBody());

        // Verify job actually ran
        $this->assertCount(1, DummyJob::$processedData);
        $this->assertEquals(123, DummyJob::$processedData[0]['id']);
    }

    public function testWorkRespectsJobLimit()
    {
        // 1. Mock Queue to return MANY jobs (more than limit 5)
        $mockQueue = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['pop', 'done'])
            ->getMock();

        // Create 10 jobs
        $jobs = [];
        for ($i = 0; $i < 10; $i++) {
            $jobs[] = new MockJob(['job' => DummyJob::class, 'data' => ['k' => $i]]);
        }
        // Append null just in case
        $jobs[] = null;

        $mockQueue->method('pop')->willReturnOnConsecutiveCalls(...$jobs);

        Services::injectMock('queue', $mockQueue);

        // 2. Config
        $config = config('StarDust');
        $config->workerToken = 'secret';

        // 3. Execute
        $result = $this->withConfig($config)
            ->controller(TestableQueueWorker::class)
            ->execute('work', 'secret');

        // 4. Verify
        // Should only process 5
        $this->assertStringContainsString('Processed 5 jobs', $result->response()->getBody());
        $this->assertCount(5, DummyJob::$processedData);
    }

    public function testWorkHandlesJobFailure()
    {
        // 1. Mock Queue with a bad job
        $mockQueue = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['pop', 'done'])
            ->getMock();

        $mockQueue->method('pop')->willReturnOnConsecutiveCalls(
            new MockJob(['job' => 'NonExistentClass', 'data' => []]), // Should fail
            new MockJob(['job' => DummyJob::class, 'data' => ['ok' => true]]), // Should succeed
            null
        );

        Services::injectMock('queue', $mockQueue);

        $config = config('StarDust');
        $config->workerToken = 'secret';

        $result = $this->withConfig($config)
            ->controller(TestableQueueWorker::class)
            ->execute('work', 'secret');

        // Should return success but note reviewed count? 
        // The controller counts loop iterations where `pop` returned a job.
        // It catches exceptions inside the loop.
        // So it should process 2 jobs (1 failed, 1 succeeded).

        $this->assertStringContainsString('Processed 2 jobs', $result->response()->getBody());
        $this->assertCount(1, DummyJob::$processedData); // Only the good one ran
    }

    public function testWorkUsesConfiguredQueueName()
    {
        // 1. Config with Custom Queue Name
        $config = config('StarDust');
        $config->workerToken = 'secret';
        $config->queueName = 'custom-queue-name';

        // 2. Mock Queue
        $mockQueue = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['pop', 'done'])
            ->getMock();

        // Expect 'pop' to be called with 'custom-queue-name'
        $mockQueue->expects($this->atLeastOnce())
            ->method('pop')
            ->with('custom-queue-name', $this->anything()) // Check first arg
            ->willReturn(null); // Return empty to stop loop

        Services::injectMock('queue', $mockQueue);

        // 3. Execute
        $this->withConfig($config)
            ->controller(TestableQueueWorker::class)
            ->execute('work', 'secret');
    }
}
