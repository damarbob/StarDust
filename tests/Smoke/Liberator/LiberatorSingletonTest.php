<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Liberator;

use PHPUnit\Framework\TestCase;
use StarDust\Daemon\PidFileGuard;
use StarDust\Exception\LiberatorSingletonViolationException;

/**
 * Phase 6a exit-criterion #3: a second Liberator process attempting
 * to start while another holds the PID-file lock fails fast with
 * {@see LiberatorSingletonViolationException}.
 *
 * Pure PHP (no DB) — mirrors the Phase 5 {@see PidFileGuardTest} but
 * exercises the daemon-agnostic exception parameter introduced in
 * Phase 6a.
 */
final class LiberatorSingletonTest extends TestCase
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

    public function testSecondAcquireThrowsLiberatorSpecificException(): void
    {
        $first = PidFileGuard::acquire(
            $this->pidDir,
            'liberator',
            LiberatorSingletonViolationException::class,
        );

        try {
            PidFileGuard::acquire(
                $this->pidDir,
                'liberator',
                LiberatorSingletonViolationException::class,
            );
            self::fail('Expected LiberatorSingletonViolationException.');
        } catch (LiberatorSingletonViolationException $e) {
            self::assertStringContainsString('liberator', $e->getMessage());
        }

        $first->release();
    }

    public function testReleaseLetsSubsequentAcquireSucceed(): void
    {
        $first = PidFileGuard::acquire(
            $this->pidDir,
            'liberator',
            LiberatorSingletonViolationException::class,
        );
        $first->release();

        $second = PidFileGuard::acquire(
            $this->pidDir,
            'liberator',
            LiberatorSingletonViolationException::class,
        );
        self::assertFileExists($second->path());
        $second->release();
    }
}
