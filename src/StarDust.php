<?php

declare(strict_types=1);

namespace StarDust;

use PDO;
use Psr\Log\LoggerInterface;
use StarDust\Bootstrap\Bootstrapper;
use StarDust\Config\Config;

/**
 * Engine entry-point class.
 *
 * Holds the injected Config and exposes typed accessors plus the
 * Phase 1 bootstrap entry point. Later phases append additional
 * entry points here without breaking this surface.
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

    /**
     * Idempotent Phase 1 bootstrap: creates the data plane, schema
     * registry, and operational tables and seeds the singleton
     * stardust_schema_version row. Safe to invoke on an
     * already-bootstrapped database.
     */
    public function bootstrap(): void
    {
        (new Bootstrapper($this->config->pdo))->run();
    }
}
