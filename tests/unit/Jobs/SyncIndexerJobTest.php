<?php

namespace StarDust\Tests\Jobs;

use CodeIgniter\Test\CIUnitTestCase;
use StarDust\Jobs\SyncIndexerJob;
use Config\Services;

// --- Stubs for Optional Dependency ---
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
// -------------------------------------

class SyncIndexerJobTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testProcessSyncsIndexes()
    {
        // 1. Mock RuntimeIndexer
        $mockIndexer = $this->getMockBuilder(\StarDust\Libraries\RuntimeIndexer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['syncIndexes'])
            ->getMock();

        $payloadData = ['table' => 'users', 'indexes' => []];

        $mockIndexer->expects($this->once())
            ->method('syncIndexes')
            ->with($payloadData); // Expect it to be called with the payload

        Services::injectMock('runtimeIndexer', $mockIndexer);

        // 2. Create Job
        $job = new SyncIndexerJob([
            'modelDefinition' => $payloadData
        ]);

        // 3. Process
        $job->process();
    }

    public function testProcessLogsErrorOnInvalidPayload()
    {
        // 1. Mock RuntimeIndexer to ensure it's NOT called
        $mockIndexer = $this->getMockBuilder(\StarDust\Libraries\RuntimeIndexer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['syncIndexes'])
            ->getMock();

        $mockIndexer->expects($this->never())->method('syncIndexes');
        Services::injectMock('runtimeIndexer', $mockIndexer);

        // 2. Job with invalid payload
        $job = new SyncIndexerJob(['foo' => 'bar']); // Missing 'modelDefinition'

        // 3. Process
        $job->process();

        // 4. Verify Log (Optional, but good practice. CIUnitTestCase doesn't auto-capture logs easily without setup, 
        // so we mainly rely on syncIndexes NOT being called).
    }

    public function testProcessRethrowsException()
    {
        // 1. Mock Indexer to throw
        $mockIndexer = $this->getMockBuilder(\StarDust\Libraries\RuntimeIndexer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['syncIndexes'])
            ->getMock();

        $mockIndexer->method('syncIndexes')->willThrowException(new \Exception('DB Error'));

        Services::injectMock('runtimeIndexer', $mockIndexer);

        // 2. Job
        $job = new SyncIndexerJob(['modelDefinition' => []]);

        // 3. Expect Exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('DB Error');

        // 4. Process
        $job->process();
    }
}
