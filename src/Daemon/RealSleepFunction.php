<?php

declare(strict_types=1);

namespace StarDust\Daemon;

final class RealSleepFunction implements SleepFunction
{
    public function sleepSeconds(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
