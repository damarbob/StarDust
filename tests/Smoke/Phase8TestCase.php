<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Filter\Limits\FilterLimits;
use StarDust\Filter\Json\JsonFilterDecoder;
use StarDust\Read\SchemaVersionCache;
use StarDust\Search\EntrySearchInterface;
use StarDust\Search\Mysql\MysqlNativeDriver;
use StarDust\Search\Mysql\SqlFilterCompiler;
use StarDust\Search\PreFlight\CapabilityChecker;
use StarDust\Search\PreFlight\FieldRefResolver;
use StarDust\Search\PreFlight\PreFlightPipeline;
use StarDust\Search\PreFlight\ValueTypeValidator;
use StarDust\Search\SearchService;

/**
 * Shared scaffolding for Phase 8 search-driver smoke tests.
 *
 * Builds on Phase 7's helper chain so the existing model / field /
 * slot fixtures stay accessible. Adds Phase 8-specific helpers:
 *
 *   - {@see makeMysqlDriver()} constructs a default {@see MysqlNativeDriver}
 *     bound to the test PDO + a {@see NullLogger} (or an injected logger).
 *   - {@see makeSearchService()} assembles the full pre-flight + driver
 *     stack — what {@see \StarDust\StarDust::search()} composes lazily.
 *   - {@see makeJsonDecoder()} returns a decoder with default limits.
 *   - {@see makeCompiler()} returns a fresh {@see SqlFilterCompiler}
 *     for tests that introspect SQL without going through the probe.
 */
abstract class Phase8TestCase extends Phase7TestCase
{
    protected function makeJsonDecoder(?FilterLimits $limits = null): JsonFilterDecoder
    {
        return new JsonFilterDecoder($limits ?? new FilterLimits());
    }

    protected function makeCompiler(): SqlFilterCompiler
    {
        return new SqlFilterCompiler();
    }

    protected function makeMysqlDriver(?LoggerInterface $logger = null): MysqlNativeDriver
    {
        $logger ??= new NullLogger();
        return new MysqlNativeDriver(
            pdo:    $this->pdo,
            logger: $logger,
            cache:  new SchemaVersionCache($this->pdo, $logger),
        );
    }

    protected function makeSearchService(
        ?EntrySearchInterface $driver = null,
        ?LoggerInterface $logger = null,
        ?FilterLimits $limits = null,
    ): SearchService {
        $logger ??= new NullLogger();
        $limits ??= new FilterLimits();
        $driver  ??= $this->makeMysqlDriver($logger);
        return new SearchService(
            driver:    $driver,
            cache:     new SchemaVersionCache($this->pdo, $logger),
            preFlight: new PreFlightPipeline(
                fieldRefResolver:   new FieldRefResolver($logger),
                capabilityChecker:  new CapabilityChecker($logger),
                valueTypeValidator: new ValueTypeValidator($logger, $limits),
            ),
            logger:    $logger,
            clock:     new SystemClock(),
        );
    }
}
