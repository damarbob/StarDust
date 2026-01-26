<?php

namespace StarDust\Tests\Jobs;

use CodeIgniter\Test\CIUnitTestCase;
use StarDust\Jobs\PurgeDeletedJob;
use StarDust\Services\EntriesManager;
use StarDust\Services\ModelsManager;
use Config\Services;

// --- Stubs for Dependencies ---
if (!interface_exists('CodeIgniter\Queue\Interfaces\JobInterface')) {
    interface JobInterfaceStub
    {
        public function process();
    }
    class_alias(JobInterfaceStub::class, 'CodeIgniter\Queue\Interfaces\JobInterface');
}

if (!class_exists('CodeIgniter\Queue\BaseJob')) {
    class BaseJobStub
    {
        public function __construct(protected array $data) {}
        public function process() {}
    }
    class_alias(BaseJobStub::class, 'CodeIgniter\Queue\BaseJob');
}
// -----------------------------

class PurgeDeletedJobTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(); // Reset services to fresh state
    }

    public function testProcessRequeuesImmediatelyIfProgressMade()
    {
        // 1. Mock EntriesManager
        $mockManager = $this->getMockBuilder(EntriesManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['purgeDeleted', 'countDeleted'])
            ->getMock();

        $mockManager->expects($this->once())
            ->method('purgeDeleted')
            ->with(100)
            ->willReturn(100);

        $mockManager->expects($this->never())->method('countDeleted');

        Services::injectMock('entriesManager', $mockManager);

        // 2. Mock Queue using stdClass to avoid class lookup issues
        $mockQueue = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['push', 'pop', 'later', 'stopping'])
            ->getMock();

        $mockQueue->expects($this->once())
            ->method('push')
            ->with(
                'default',
                PurgeDeletedJob::class,
                ['type' => 'entries', 'stuck_count' => 0, 'total_purged' => 100]
            );

        Services::injectMock('queue', $mockQueue);

        // 3. Process
        $job = new PurgeDeletedJob(['type' => 'entries']);
        $job->process();
    }

    public function testProcessRetriesIfStuckButWorkRemains()
    {
        // 1. Mock ModelsManager
        $mockManager = $this->getMockBuilder(ModelsManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['purgeDeleted', 'countPurgeableDeleted']) // Updated method
            ->getMock();

        $mockManager->expects($this->once())->method('purgeDeleted')->willReturn(0);
        $mockManager->expects($this->once())->method('countPurgeableDeleted')->willReturn(50); // Updated method

        Services::injectMock('modelsManager', $mockManager);

        // 2. Mock Queue
        $mockQueue = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['push', 'pop', 'later', 'stopping'])
            ->getMock();

        $mockQueue->expects($this->once())
            ->method('later')
            ->with(
                5,
                'default',
                PurgeDeletedJob::class,
                ['type' => 'models', 'stuck_count' => 1, 'total_purged' => 0]
            );

        Services::injectMock('queue', $mockQueue);

        // 3. Process
        $job = new PurgeDeletedJob(['type' => 'models', 'stuck_count' => 0]);
        $job->process();
    }

    public function testProcessAbortsAfterMaxRetries()
    {
        // 1. Mock EntriesManager
        $mockManager = $this->getMockBuilder(EntriesManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['purgeDeleted', 'countDeleted'])
            ->getMock();

        $mockManager->method('purgeDeleted')->willReturn(0);
        $mockManager->method('countDeleted')->willReturn(50);

        Services::injectMock('entriesManager', $mockManager);

        // 2. Mock Queue
        $mockQueue = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['push', 'pop', 'later', 'stopping'])
            ->getMock();

        $mockQueue->expects($this->never())->method('push');

        Services::injectMock('queue', $mockQueue);

        // 3. Process
        $job = new PurgeDeletedJob(['type' => 'entries', 'stuck_count' => 3]);
        $job->process();
    }

    public function testProcessCompletesWhenNoRemaining()
    {
        // 1. Mock EntriesManager
        $mockManager = $this->getMockBuilder(EntriesManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['purgeDeleted', 'countDeleted'])
            ->getMock();

        $mockManager->method('purgeDeleted')->willReturn(0);
        $mockManager->method('countDeleted')->willReturn(0);

        Services::injectMock('entriesManager', $mockManager);

        // 2. Mock Queue
        $mockQueue = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['push', 'pop', 'later', 'stopping'])
            ->getMock();

        $mockQueue->expects($this->never())->method('push');
        Services::injectMock('queue', $mockQueue);

        // 3. Process
        $job = new PurgeDeletedJob(['type' => 'entries']);
        $job->process();
    }
    public function testProcessModelsSucceedsWhenBlockedByEntries()
    {
        // 1. Mock ModelsManager
        $mockManager = $this->getMockBuilder(ModelsManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['purgeDeleted', 'countPurgeableDeleted'])
            ->getMock();

        $mockManager->expects($this->once())->method('purgeDeleted')->willReturn(0);
        // Returns 0 purgeable (blocked by entries), effectively "done" for this job run
        $mockManager->expects($this->once())->method('countPurgeableDeleted')->willReturn(0);

        Services::injectMock('modelsManager', $mockManager);

        // 2. Mock Queue
        $mockQueue = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['push', 'pop', 'later', 'stopping'])
            ->getMock();

        // Should NOT push back to queue (Success/Exit)
        $mockQueue->expects($this->never())->method('push');
        Services::injectMock('queue', $mockQueue);

        // 3. Process
        $job = new PurgeDeletedJob(['type' => 'models']);
        $job->process();
    }
}
