<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Daemon;

use PHPUnit\Framework\TestCase;
use StarDust\Daemon\PidFileGuard;
use StarDust\Exception\WatcherSingletonViolationException;

/**
 * Watcher singleton exit criterion: a second `acquire()` against the
 * same pid file throws {@see WatcherSingletonViolationException}.
 */
final class PidFileGuardTest extends TestCase
{
    private string $pidDir;

    protected function setUp(): void
    {
        $this->pidDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-pid-' . bin2hex(random_bytes(4));
        mkdir($this->pidDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pidDir) || !is_dir($this->pidDir)) {
            return;
        }
        foreach (glob($this->pidDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->pidDir);
    }

    public function testSecondAcquireThrows(): void
    {
        $first = PidFileGuard::acquire($this->pidDir, 'watcher');

        try {
            PidFileGuard::acquire($this->pidDir, 'watcher');
            self::fail('Expected WatcherSingletonViolationException.');
        } catch (WatcherSingletonViolationException $e) {
            self::assertStringContainsString('watcher', $e->getMessage());
        }

        $first->release();
    }

    public function testReleaseLetsSubsequentAcquireSucceed(): void
    {
        $first = PidFileGuard::acquire($this->pidDir, 'watcher');
        $first->release();

        $second = PidFileGuard::acquire($this->pidDir, 'watcher');
        self::assertFileExists($second->path());
        $second->release();
    }

    public function testPidFilePreservedAfterRelease(): void
    {
        $guard = PidFileGuard::acquire($this->pidDir, 'watcher');
        $path = $guard->path();
        $guard->release();

        self::assertFileExists($path);
        self::assertSame((string) getmypid(), trim((string) file_get_contents($path)));
    }
}
