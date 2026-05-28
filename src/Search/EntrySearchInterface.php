<?php

declare(strict_types=1);

namespace StarDust\Search;

use StarDust\Read\Entry;

/**
 * Phase 8 driver contract per the Search Driver Adapter blueprint §5.
 *
 * Implementations service read requests against a backing store —
 * MySQL natively (Phase 4 read path, ADR 0005) or an external search
 * engine (Meilisearch, OpenSearch). The engine never writes through a
 * driver; ingestion always targets MySQL. Drivers are read-only.
 *
 * The interface deliberately exposes capability introspection rather
 * than runtime `try/catch` against unsupported operations:
 *
 *   - {@see supportedOperators()} declares which closed-v1 operators
 *     (plus any driver-declared extensions per ADR 0022) the driver
 *     services.
 *   - {@see supportsFilterOn()} answers per-field filterability; the
 *     MySQL driver returns the registry's `is_filterable` flag, while
 *     external drivers consult their own index metadata.
 *   - {@see supportsFuzzySearch()} is a feature-level flag for caller
 *     gating.
 *   - {@see consistencyModel()} returns `"strong"` or `"eventual"`
 *     per {@see ConsistencyModel}.
 *
 * Pre-flight runs the capability checks *before* `list()` is called;
 * implementations may assume the request reaches them already validated.
 */
interface EntrySearchInterface
{
    /**
     * Executes the request and returns a paginated result. The driver
     * may assume that:
     *   - every {@see \StarDust\Filter\Ast\LeafNode} has a resolved
     *     {@see \StarDust\Filter\Ast\FieldRef} (descriptor populated);
     *   - the operator on every leaf is in `supportedOperators()`;
     *   - every leaf's field passes `supportsFilterOn(field_id)`.
     */
    public function list(SearchRequest $request): SearchResult;

    /**
     * Point read by entry id, scoped to a tenant. Returns `null` when
     * the entry does not exist or belongs to a different tenant.
     */
    public function get(int $tenantId, int $entryId): ?Entry;

    /**
     * The closed set of leaf-operator names this driver services.
     * Subset of {@see \StarDust\Filter\Operator::CLOSED_V1}, optionally
     * extended with driver-declared operators (ADR 0022 capability
     * extensions).
     *
     * @return list<string>
     */
    public function supportedOperators(): array;

    /**
     * Whether this driver can filter on the given field id.
     */
    public function supportsFilterOn(int $fieldId): bool;

    /**
     * Whether this driver implements fuzzy / approximate matching.
     * The MySQL native driver returns `false`.
     */
    public function supportsFuzzySearch(): bool;

    /**
     * Consistency model identifier. One of {@see ConsistencyModel::STRONG}
     * or {@see ConsistencyModel::EVENTUAL}.
     */
    public function consistencyModel(): string;
}
