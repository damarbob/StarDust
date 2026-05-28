<?php

declare(strict_types=1);

namespace StarDust\Read;

use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Clock\SystemClock;
use StarDust\Search\EntrySearchInterface;
use StarDust\Search\Mysql\MysqlNativeDriver;
use StarDust\Search\PreFlight\CapabilityChecker;
use StarDust\Search\PreFlight\FieldRefResolver;
use StarDust\Search\PreFlight\PreFlightPipeline;
use StarDust\Search\PreFlight\ValueTypeValidator;
use StarDust\Search\SearchRequest;
use StarDust\Search\SearchService;
use StarDust\Write\TenantId;

/**
 * Phase 4 read-path façade — preserved as a thin legacy adapter over
 * the Phase 8 pipeline.
 *
 *   - `read(EntryQuery)` builds a {@see SearchRequest} from the legacy
 *     DTO and delegates to {@see SearchService}, which runs the same
 *     two-query bounded sequence of ADR 0005 plus the pre-flight
 *     pipeline of the QueryFilter wire-format blueprint §4.7.
 *   - `get(int, int)` is a tenant-isolated point read delegating to
 *     the active driver (defaults to {@see MysqlNativeDriver}).
 *
 * The constructor signature is unchanged from Phase 4 — `(PDO,
 * LoggerInterface)` — so existing tests and consumers do not need to
 * thread a Phase 8 driver through. An injected driver can be supplied
 * for the (rare) caller that needs to switch backends at the
 * EntryReader level rather than at the {@see \StarDust\Config\Config}
 * level.
 */
final class EntryReader
{
    private readonly EntrySearchInterface $driver;
    private readonly SearchService $service;

    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
        ?EntrySearchInterface $driver = null,
        ?ClockInterface $clock = null,
    ) {
        $cache = new SchemaVersionCache($this->pdo, $this->logger);
        $this->driver = $driver ?? new MysqlNativeDriver(
            pdo:    $this->pdo,
            logger: $this->logger,
            cache:  $cache,
        );
        $pipeline = new PreFlightPipeline(
            fieldRefResolver:   new FieldRefResolver($this->logger),
            capabilityChecker:  new CapabilityChecker($this->logger),
            valueTypeValidator: new ValueTypeValidator($this->logger),
        );
        $this->service = new SearchService(
            driver:    $this->driver,
            cache:     $cache,
            preFlight: $pipeline,
            logger:    $this->logger,
            clock:     $clock ?? new SystemClock(),
        );
    }

    public function read(EntryQuery $query): EntryPage
    {
        TenantId::assertValid($query->tenantId);
        $request = SearchRequest::fromEntryQuery($query);
        $result = $this->service->execute($request);
        return $result->toEntryPage();
    }

    public function get(int $tenantId, int $entryId): ?Entry
    {
        TenantId::assertValid($tenantId);
        return $this->driver->get($tenantId, $entryId);
    }
}
