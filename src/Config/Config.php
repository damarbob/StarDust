<?php

declare(strict_types=1);

namespace StarDust\Config;

use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;

/**
 * Construction-time configuration object per ADR 0026.
 *
 * Phase 0 surface only. Additional fields (search driver, daemon tuning)
 * will be appended in later phases without breaking the public constructor.
 */
final class Config
{
    public readonly LoggerInterface $logger;
    public readonly ClockInterface $clock;

    public function __construct(
        public readonly PDO $pdo,
        ?LoggerInterface $logger = null,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->logger = $logger ?? new StdoutNdjsonLogger($this->clock);
    }
}
