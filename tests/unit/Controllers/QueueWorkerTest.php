<?php

namespace StarDust\Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\DatabaseTestTrait;
use StarDust\Controllers\QueueWorker;
use Config\Services;

class QueueWorkerTest extends CIUnitTestCase
{
    use ControllerTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testWorkWithoutTokenReturnsForbidden()
    {
        // Mock Config
        $config = config('StarDust');
        $config->workerToken = 'secret';

        // Execute controller
        $result = $this->withConfig($config)
            ->controller(QueueWorker::class)
            ->execute('work');

        // Verify response
        $this->assertTrue($result->response()->getStatusCode() === 403);
        $this->assertStringContainsString('Forbidden', $result->response()->getBody());
    }

    public function testWorkWithInvalidTokenReturnsForbidden()
    {
        // Mock Config
        $config = config('StarDust');
        $config->workerToken = 'secret';

        // Execute controller with wrong token
        $result = $this->withConfig($config)
            ->controller(QueueWorker::class)
            ->execute('work', 'wrong-token');

        $this->assertTrue($result->response()->getStatusCode() === 403);
    }

    public function testWorkWithCorrectTokenProceeds()
    {
        // Mock Config
        $config = config('StarDust');
        $config->workerToken = 'secret';

        // Since the Queue library is NOT installed in this test environment,
        // passing the security check (valid token) should result in hitting
        // the next check: "Queue library not installed" -> 500.
        // This confirms we successfully passed the security check (which would be 403).

        $result = $this->withConfig($config)
            ->controller(QueueWorker::class)
            ->execute('work', 'secret');

        $this->assertEquals(500, $result->response()->getStatusCode());
        $this->assertStringContainsString('Queue library not installed', $result->response()->getBody());
    }

    public function testWorkWithNoConfigTokenReturnsForbidden()
    {
        // Mock Config with NO token
        $config = config('StarDust');
        $config->workerToken = null;

        // Even with a token provided in URL, it should fail if server config is empty
        $result = $this->withConfig($config)
            ->controller(QueueWorker::class)
            ->execute('work', 'some-token');

        $this->assertEquals(403, $result->response()->getStatusCode());
    }
}
