<?php

declare(strict_types=1);

namespace StarDust\Daemon;

/**
 * Injectable sleep abstraction so smoke tests can drive {@see PollLoop}
 * without sitting on real wall-clock seconds.
 */
interface SleepFunction
{
    public function sleepSeconds(int $seconds): void;
}
