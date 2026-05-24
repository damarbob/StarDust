<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Daemon;

use PHPUnit\Framework\TestCase;
use StarDust\Daemon\FlagFileShutdownSignal;
use StarDust\Daemon\PollLoop;
use StarDust\Daemon\ShutdownSignal;
use StarDust\Daemon\SleepFunction;
use StarDust\Daemon\Tickable;

/**
 * `PollLoop::run()` surfaces shutdown within one sleep slice. Two
 * shapes covered: a programmatic shutdown signal after N ticks, and
 * the file-flag fallback used on hosts without ext-pcntl.
 */
final class PollLoopTest extends TestCase
{
    public function testStopsAfterShutdownSignalRequested(): void
    {
        $ticks = 0;
        $tickable = new class ($ticks) implements Tickable {
            /** @param int $counter */
            public function __construct(private int &$counter)
            {
            }
            public function tick(): void
            {
                $this->counter++;
            }
        };

        $signal = new class implements ShutdownSignal {
            public int $calls = 0;
            public function isRequested(): bool
            {
                return ++$this->calls > 2;
            }
        };

        $sleeper = new class implements SleepFunction {
            public int $sleeps = 0;
            public function sleepSeconds(int $seconds): void
            {
                $this->sleeps++;
            }
        };

        $loop = new PollLoop($sleeper);
        $loop->run($tickable, $signal, 0);

        self::assertGreaterThanOrEqual(1, $ticks);
        self::assertLessThanOrEqual(2, $ticks, 'Loop should exit promptly after shutdown is requested.');
    }

    public function testFlagFileShutdownTriggersWithinOneSlice(): void
    {
        $pidDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-flag-' . bin2hex(random_bytes(4));
        mkdir($pidDir, 0777, true);

        try {
            $signal = new FlagFileShutdownSignal($pidDir, 'reconciler');
            self::assertFalse($signal->isRequested());

            touch($signal->path());
            self::assertTrue($signal->isRequested(), 'Flag file should be observed immediately.');
        } finally {
            @unlink($pidDir . DIRECTORY_SEPARATOR . 'reconciler.shutdown');
            @rmdir($pidDir);
        }
    }
}
