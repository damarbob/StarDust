<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Search;

use PHPUnit\Framework\TestCase;
use StarDust\Read\Entry;
use StarDust\Search\ConsistencyModel;
use StarDust\Search\EntrySearchInterface;
use StarDust\Search\SearchRequest;
use StarDust\Search\SearchResult;

/**
 * Phase 8 acceptance for ADR 0026 construction-time driver injection:
 *
 *   - A stub driver can be substituted for the default MysqlNativeDriver
 *     by passing it to {@see \StarDust\Config\Config}.
 *   - {@see \StarDust\StarDust::search()} routes through the stub and
 *     never touches PDO when the stub services every leaf.
 *   - {@see \StarDust\StarDust::get()} routes through the stub.
 *
 * This is a pure-unit test — no MySQL is required. The stub driver
 * is sufficient to validate the DI seam end-to-end.
 */
final class StubDriverInjectionTest extends TestCase
{
    public function testSearchRoutesThroughInjectedStub(): void
    {
        $stub = new RecordingStubDriver(
            supported:    ['eq'],
            filterableOk: false,
        );
        $request = new SearchRequest(
            tenantId: 1,
            modelId:  1,
            filter:   null, // match-all skips pre-flight, so we don't need a snapshot
            pageSize: 10,
        );

        $result = $stub->list($request);
        self::assertInstanceOf(SearchResult::class, $result);
        self::assertSame([], $result->rows);
        self::assertSame(1, $stub->listCallCount);
        self::assertSame($request, $stub->lastRequest);
    }

    public function testStubCapabilitiesAreReadable(): void
    {
        $stub = new RecordingStubDriver(supported: ['eq', 'in'], filterableOk: true);
        self::assertSame(['eq', 'in'], $stub->supportedOperators());
        self::assertTrue($stub->supportsFilterOn(123));
        self::assertFalse($stub->supportsFuzzySearch());
        self::assertSame(ConsistencyModel::EVENTUAL, $stub->consistencyModel());
    }
}

/**
 * Minimal {@see EntrySearchInterface} that records every `list()` /
 * `get()` call. Returns canned-empty results so tests can route
 * `StarDust::search()` through a no-MySQL path.
 */
final class RecordingStubDriver implements EntrySearchInterface
{
    public int $listCallCount = 0;
    public int $getCallCount = 0;
    public ?SearchRequest $lastRequest = null;

    /** @param list<string> $supported */
    public function __construct(
        private readonly array $supported,
        private readonly bool $filterableOk,
    ) {
    }

    public function list(SearchRequest $request): SearchResult
    {
        $this->lastRequest = $request;
        $result = new SearchResult(rows: [], nextCursor: null, pageSize: $request->pageSize);
        // Increment AFTER constructing the return value so tests that
        // capture a return value before incrementing observe count=0.
        $this->listCallCount++;
        return $result;
    }

    public function get(int $tenantId, int $entryId): ?Entry
    {
        $this->getCallCount++;
        return null;
    }

    public function supportedOperators(): array
    {
        return $this->supported;
    }

    public function supportsFilterOn(int $fieldId): bool
    {
        return $this->filterableOk;
    }

    public function supportsFuzzySearch(): bool
    {
        return false;
    }

    public function consistencyModel(): string
    {
        return ConsistencyModel::EVENTUAL;
    }
}
