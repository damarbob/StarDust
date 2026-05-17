<?php

declare(strict_types=1);

namespace StarDust;

use PDO;
use Psr\Log\LoggerInterface;
use StarDust\Config\Config;

/**
 * Engine entry-point class (Phase 0).
 *
 * No business logic. Holds the injected Config and exposes typed
 * accessors so Phase 0 smoke tests and later phase implementations
 * can pull the PDO connection and logger they need.
 */
final class StarDust
{
    public const VERSION = '0.3.0-alpha.1';

    public function __construct(private readonly Config $config)
    {
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function pdo(): PDO
    {
        return $this->config->pdo;
    }

    public function logger(): LoggerInterface
    {
        return $this->config->logger;
    }
}
