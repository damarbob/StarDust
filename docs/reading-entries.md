# Reading entries

The two-query bounded read, cursor pagination, point reads, and sorting.

```php
use StarDust\Filter\Ast\AndNode;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Ast\NotNode;
use StarDust\Filter\Ast\OrNode;
use StarDust\Read\EntryQuery;

// Cursor-paginated read. Two-query bounded sequence:
//   1) Paginated Probe selects entry_data.id with LIMIT pageSize+1
//      (the extra row is the sole next-page signal — no COUNT(*),
//      no OFFSET).
//   2) Bounded Fetch materialises only those IDs plus the indexed
//      slot columns needed to assemble the caller's selectFields.
// Filters on fields with is_filterable=false or whose slot is
// backfilling/tombstoned/unmapped are rejected pre-flight with a
// typed exception — no SQL is issued.
//
// Filters are AST trees: leaves carry (operator, field, value);
// composites are AndNode / OrNode / NotNode. Pure-AND chains keep
// the original INNER-JOIN-per-page execution shape; trees that
// contain OR or NOT switch to EXISTS subqueries automatically.
$page = $engine->read(new EntryQuery(
    tenantId:     42,
    modelId:      $modelId,
    filter:       LeafNode::local('name', 'eq', 'Acme'),
    selectFields: ['name', 'employees'],
    pageSize:     100,
));

// Multiple AND-composed leaves:
$page = $engine->read(new EntryQuery(
    tenantId: 42,
    modelId:  $modelId,
    filter:   new AndNode([
        LeafNode::local('status', 'eq', 'active'),
        LeafNode::local('employees', 'gt', 100),
    ]),
));

// Full boolean composition:
$filter = new AndNode([
    new OrNode([
        LeafNode::local('region', 'eq', 'eu'),
        LeafNode::local('region', 'eq', 'us'),
    ]),
    new NotNode(LeafNode::local('status', 'eq', 'archived')),
]);
// $page->rows           — list<Entry>
// $page->nextCursor     — Cursor|null; null means last page
// $page->pageSize       — echo of the requested size

// Page through to exhaustion. The cursor is opaque — pass it back
// unchanged; do not inspect it. It marks only a position, not the
// query, so send the same filter, selectFields and page size with
// every page. A changed sort is refused outright, but a missing
// filter is not: the pages after the first silently come back
// unfiltered.
$cursor = null;
do {
    $page = $engine->read(new EntryQuery(
        tenantId:     42,
        modelId:      $modelId,
        filter:       $filter,
        selectFields: ['name', 'employees'],
        pageSize:     100,
        cursor:       $cursor,
    ));
    // ... use $page->rows
    $cursor = $page->nextCursor;
} while ($cursor !== null);

// Point read by (tenant_id, entry_id). Returns null when the entry
// does not exist for this tenant (or has been soft-deleted).
$entry = $engine->get(tenantId: 42, entryId: $someEntryId);
// $entry?->id, $entry?->fields, $entry?->createdAt
```

Fields are sourced from the joined slot column when the slot's status is `assigned` or `ready`; otherwise — `backfilling`, `tombstoned`, or unmapped — they fall back to the JSON payload stored in `entry_data.fields`. This preserves write-availability on the read side: a field that lacks an indexed slot still surfaces, just without filter or sort capability. The read path emits NDJSON events `search_request`, `pre_flight_rejected`, and `capability_unsupported`; `cache_miss` is emitted by the in-process schema-version cache on registry-version bumps.

## Sorting

Reads are ordered by insertion order unless you say otherwise. Pass a `SortSpec` to order by an entry's creation, or by any field that currently has an indexed slot:

```php
use StarDust\Read\{EntryQuery, SortSpec, SortDirection};

// Newest first — the common case, and the cheapest: it resolves to a
// backward index scan, no sorting work at all.
$page = $engine->read(new EntryQuery(
    tenantId: 42,
    modelId:  $modelId,
    sort:     SortSpec::byId(SortDirection::Desc),
));

// By creation time, or by one of your own fields.
SortSpec::byCreatedAt(SortDirection::Desc);
SortSpec::byField('title');                        // ascending
SortSpec::byField('price', SortDirection::Desc);
```

Sorting composes with filters and with cursor pagination — keep passing the `nextCursor` back as usual, together with the same filter and sort.

Four things worth knowing:

- **Only indexed fields are sortable.** A field must be declared filterable and hold a live slot, the same requirement filtering has. Sorting on anything else raises `FieldNotSortableException`, and on an unregistered name `UnknownFieldException`. `describeModel()` reports which fields qualify right now via `ModelDescription::indexedFields()`.
- **Entries with no value for the sort field sort first ascending, last descending** — they are not dropped from the page.
- **A cursor belongs to the ordering that produced it.** Change the sort key or its direction and the old cursor is refused with `InvalidCursorException`; start again from the first page. This is a guard, not a limitation to work around — reusing it would silently walk a different sequence. **The filter gets no such guard:** a cursor records a position and the ordering, never the filter, so a follow-up request that omits or changes the filter is accepted and walks the unfiltered sequence from that point.
- **On MariaDB, a field sort orders supplementary-plane characters** — mostly emoji, well outside everyday text — **at the opposite end from MySQL.** Every other comparison, and ordinary text in any language, sorts identically on both engines.

Sorting by `id` or by creation time costs nothing extra. Sorting by one of your own fields makes the database order the whole matching set on each page, so it is measurably more expensive on large models — prefer the built-in orderings when either will do.
