# StarDust

**Schemaless dynamic fields, queried at native SQL index speed — no separate search cluster, no EAV join swamp.**

Give every tenant their own fields, then filter them like first-class columns:

```php
// "industry" and "employees" are user-defined fields, not table columns —
// yet this compiles to an indexed range scan, not a table-scan-and-pray.
$page = $engine->read(new EntryQuery(
    tenantId: 1,
    modelId:  $companyModelId,
    filter:   new AndNode([
        LeafNode::local('industry',  'eq', 'software'),
        LeafNode::local('employees', 'gt', 100),
    ]),
    selectFields: ['name', 'employees'],
));

foreach ($page->rows as $company) {
    echo "{$company->fields['name']} — {$company->fields['employees']}\n";
}
```

If you've ever reached for an EAV table and then watched the self-joins melt your database, StarDust is the engine you wanted instead. The complete JSON payload is always the system of record; filterable fields are mirrored into pre-provisioned, indexed slot columns — so reads hit real indexes while writes stay available even when capacity runs low.

## Try it in five minutes

```bash
docker compose up
```

This brings up MySQL, bootstraps the schema, seeds a sample `company` model, runs the query above, and starts the four background daemons. The seeded results print in the `init` service logs:

```bash
docker compose logs init
```

Want to tinker? [`docker/seed.php`](docker/seed.php) is the whole flow — define a model, make fields filterable, write entries, query — as readable, copy-pasteable example code.

Some StarDust behaviour only makes sense as a sequence in time, and [`examples/`](examples/) covers that: small scripts that seed their own data, narrate themselves in the terminal, and clean up after. Start with [`examples/01-field-lifecycle.php`](examples/01-field-lifecycle.php), which answers the question that trips up nearly everyone — why a field you just marked filterable still cannot be filtered, and what has to happen before it can.

> **Heads up — this is a v0.3.0 pre-release.** `main` and the `0.3.x` tags are a breaking architectural migration (**Vertical Schema Partitioning**) away from the legacy 0.2.x line, motivated by scalability limits and OOM vulnerabilities in the old Virtual Column design. If you need something production-ready today, stay on `^0.2.0-alpha.x` — critical 0.2.x fixes land on the `support/v0.2` branch. Otherwise, read on; the honest caveats live in [Is StarDust a fit?](#is-stardust-a-fit) and [Status](#status), not buried in the fine print.

StarDust ships as a **framework-neutral Composer library** with zero runtime framework dependencies — only the `psr/log` and `psr/clock` interfaces. Framework adapters (CodeIgniter 4 first) are opt-in companion packages, never core requirements.

---

## Contents

### In this README

- [Try it in five minutes](#try-it-in-five-minutes)
- [Architecture at a glance](#architecture-at-a-glance)
- [Is StarDust a fit?](#is-stardust-a-fit)
- [Status](#status)
- [Requirements](#requirements)
- [Deployment Requirements](#deployment-requirements)
- [Installation](#installation)
- [Complete example](#complete-example)
- [Testing](#testing)
- [Contributing](#contributing)
- [Legacy](#legacy)
- [License](#license)

### Reference docs

- [Deployment requirements](docs/deployment.md)
- [Construction & schema bootstrap](docs/configuration.md)
- [Writing entries](docs/writing-entries.md)
- [Updating and deleting entries](docs/writing-entries.md#updating-and-deleting-entries)
- [Reading entries](docs/reading-entries.md)
- [Searching with the JSON wire format](docs/query-filter.md)
- [Custom search drivers](docs/custom-search-drivers.md)
- [Changing a field's type or filterability](docs/schema-changes.md)
- [Async exports](docs/exports.md)
- [Slot maintenance](docs/slot-maintenance.md)
- [Tracing a request through the logs](docs/observability.md)
- [Errors](docs/errors.md)
- [CLI](docs/cli.md)

---

## Architecture at a glance

Every entry's full payload is stored as JSON in `entry_data` — that's the system of record, and it always holds the complete record. Filterable fields are *mirrored* into typed, indexed slot columns on an extension page, so a filter query reads an index instead of scanning JSON:

```text
                       write(EntryPayload)
                                │
                                ▼
   ┌─────────────────────────────────────────────────────────────┐
   │  entry_data            (system of record — full payload)    │
   │  id │ tenant_id │ model_id │ fields (JSON)                  │
   │   7 │     1     │    42    │ {"name":"Acme","employees":340,│
   │     │           │          │  "city":"Berlin"}              │
   └─────────────────────────────────────────────────────────────┘
                                │  mirror the filterable fields
                                │  into typed slot columns
                                ▼
   ┌─────────────────────────────────────────────────────────────┐
   │  entry_slots_page_1    (indexed 1:1 extension page)         │
   │  entry_id │ i_str_01 │ i_int_01 │ …  (typed slot columns)   │
   │     7     │  "Acme"  │   340    │                           │
   │           │ (name)   │(employees)                           │
   └─────────────────────────────────────────────────────────────┘
        ▲ composite index (tenant_id, i_str_01), (tenant_id, i_int_01), …

   "city" was never made filterable → it never occupies a slot
   column at all. It lives in JSON only: still readable, just not
   indexed. A filterable field that outruns slot capacity also
   stays in JSON and is queued for backfill — the write never
   fails for lack of a slot.
```

Four background daemons keep the slot machinery healthy. They never talk to each other directly — MySQL is the only coordination point:

```text
        ┌──────────── MySQL — sole coordination point ──────────────┐
        │   entry_data · entry_slots_page_N · stardust_* registry   │
        └───────────────────────────────────────────────────────────┘
             ▲             ▲              ▲                ▲
   provisions│     drains  │    reclaims  │      streams   │
   capacity  │     queues  │    freed     │      exports   │
             │             │    slots     │                │
      ┌──────────┐  ┌────────────┐  ┌────────────┐  ┌────────────┐
      │ Watcher  │  │ Reconciler │  │ Liberator  │  │ Chronicler │
      │ singleton│  │multi-worker│  │multi-worker│  │multi-worker│
      └──────────┘  └────────────┘  └────────────┘  └────────────┘
   adds indexed   backfills the    sweeps tombstoned  writes async
   pages when     sync queue,      slot columns back  CSV/JSON
   capacity is    async imports,   to free for reuse  export
   low            and retypes                         artifacts
```

Some vocabulary here is specific to StarDust — *slot*, *page*, *spread*, *backfill window*. **[GLOSSARY.md](GLOSSARY.md)** defines every term in plain language and opens with a short walkthrough that threads them together, if you would rather get the whole model in one pass before reading on.

---

## Is StarDust a fit?

**A good fit if you:**

- Need user-defined or per-tenant dynamic fields that are still **filterable at native SQL index speed**, without standing up a separate search cluster.
- Already run **MySQL 8.0.13+ (or Percona)**, or **MariaDB 10.11+**, either as persistent background processes (systemd, supervisor, or containers) or as a scheduled `bin/stardust tick` on a host with no persistent-process capability.
- Want a **framework-neutral** engine you can drop into any PHP app via Composer — no ORM, query builder, or framework pulled in.
- Can tolerate a newly defined or retyped filterable field becoming queryable **shortly after** the fact rather than instantly.

**Probably not a fit if you:**

- Are tied to **MariaDB ≤ 10.6 or MySQL ≤ 5.7** — both are actively rejected (see [Requirements](#requirements)). MariaDB 10.11+ is supported, with one caveat: range filters and field sorts order supplementary-plane characters (rare outside emoji) at the opposite end from MySQL.
- Need **strong read-after-write consistency on filters immediately after a retype or filterability promotion.** The field is served from the JSON payload (and is not filterable) until its backfill completes.
- Need **full-text, fuzzy, or substring search** out of the box. The default MySQL driver ships exact-match, comparison, range, set-membership, and *anchored*-prefix (`LIKE 'x%'`) operators — but no substring/suffix matching, no fuzzy matching, and no relevance ranking. Fuzzy/full-text is a capability you'd supply via a custom driver.
- Need **page numbers, jump-to-page navigation, or a total result count.** Reads are cursor-paginated and forward-sequential: every page hands you an opaque cursor for the next one, and the absence of a cursor means you have reached the end. There is no offset parameter and no total count, and that is deliberate rather than pending — both require the database to read the entire matching set, so a query that is quick today would slow down purely because the tenant grew. Infinite scroll and a Next button work naturally; a Back button means holding on to the cursors you have already used, and "Page 7 of 214" or a deep link to an arbitrary page cannot be served at all. A driver backed by an external search service can maintain its own index and supply them.

---

## Status

**This is a v0.3.0 pre-release.** Phases 0 (operating-environment verification and the package skeleton), 1 (schema registry and core data plane), 2 (slot & page system), 3 (write path), 4 (read path), 5 (resilience daemons: Watcher + Reconciler), 6a (slot reclamation: Liberator), 6b (field retype & filterability-promotion pipeline), 7 (async exports: Chronicler), and 8 (search driver: JSON query-filter wire format, filter AST, and a swappable execution adapter) are implemented.

**What works today:**

- **Schema bootstrap** — idempotent, non-destructive provisioning of every table the engine needs.
- **Slot & page system** — auto-allocated `entry_slots_page_N` extension pages, indexed according to each field's `is_filterable` flag, with atomic free-slot reservation. Reservation keeps a model's filterable fields together on as few pages as it can, so filtered queries stay at fewer joins as a model grows.
- **Writes** — single-entry, synchronous chunked bulk (≤ 1 000 per call), and async submission for larger batches. Writes stay available even when slot capacity is exhausted: the value still lands in the JSON payload and is queued for backfill.
- **Entry updates and deletes** — `updateEntry()` replaces an entry's fields wholesale, rewriting both the JSON payload and the indexed slot columns, and clearing the slot of any field the new payload omits so a filter can never match a stale value. `deleteEntry()` soft-deletes: one timestamp, after which the entry is gone from reads, filters, point-reads, and exports alike.
- **Reads** — cursor-paginated, two-query bounded read; tenant-isolated SQL on every `WHERE` and `JOIN`; an in-process schema-version cache.
- **Search** — a unified `search()` surface; JSON wire format decoded into a closed filter AST (twelve operators, full AND/OR/NOT); three-stage pre-flight validation on the filter tree (field resolution, capability, value type) plus a fourth stage that validates the sort key and cursor agreement; a swappable driver (the built-in native driver keeps pure-AND filters on indexed joins and switches to `EXISTS` subqueries for OR/NOT — inject your own to delegate to an external search service).
- **Background daemons** (all runnable via `bin/stardust`): the **Watcher** keeps slot capacity provisioned and indexes each new page for the fields currently waiting on one, the **Reconciler** drains six work sources (sync queue, async imports, retype backfills, rename rewrites, field-deletion purges, and model-deletion purges), claiming a slot for any filterable field still waiting on one, with a dead-letter queue and operator replay, and auto-recovery of import jobs abandoned by a crashed worker — resumed from the last committed checkpoint — the **Liberator** reclaims tombstoned slots, and the **Chronicler** streams CSV/JSON exports to disk.
- **Field lifecycle** — online field retype, and filterability promotion and demotion, through a type-coercion matrix, with JSON-payload fallback throughout the backfill window. Demotion is registry-only and takes effect immediately: the slot is tombstoned for the Liberator to reclaim, and reads fall straight back to the payload.
- **Model rename** — `renameModel()` is immediate and complete when it returns: a model's name is a label, not an identity, so entries, slots, filters and exports all keep working untouched and there is no background catch-up to wait for. One caveat: `schemaBuilder()`'s `createModel()` / `defineModel()` find a model by name, so a setup or seed script still using the old name will create a **second** model rather than finding the renamed one — update those scripts in step with the rename.
- **Online field rename** — `renameField()` returns as soon as the registry is updated, and the stored data catches up in the background. Because each entry's JSON payload is keyed by field name, a rename rewrites every entry in the model, so it needs a running Reconciler to finish. Nothing breaks while it runs: reads return the value under the new name for every entry, migrated or not; a client still sending the old name keeps working, because inbound writes are rewritten to the new name before they are stored; and filters on the new name work from the moment the call returns, since a rename never disturbs the index. Filters using the *old* name are rejected outright rather than silently returning nothing. A field being renamed cannot be retyped, promoted, demoted, or compacted until the rewrite finishes.
- **Model deletion** — `deleteModel()` removes a model, its fields and all of its entries. It returns as soon as the registry is updated, and from that moment the model is gone from `listModels()` and `describeModel()`, while reads of it go dark — an empty page, as if it had never existed. Destroying the data happens in the background and needs a running Reconciler. Unlike a field deletion, **writes to the model are refused** rather than quietly dropped, because an entry written to a model being erased has nowhere to live. This is the only operation in the library that physically deletes entry rows: there is no undelete, so export first if you might want the data back.
- **Field deletion** — `deleteField()` removes a field and its stored values. It returns as soon as the registry is updated, and from that moment the field is gone everywhere you can observe it: reads and `describeModel()` stop reporting it, filters against it are rejected, new CSV exports drop its column, and writes still sending its name have the value dropped. Clearing the values out of already-stored entries happens in the background, so it needs a running Reconciler to finish — until it does, the data is still physically present in the table (and visible in the JSON artifact of an export that runs during the window), just unreachable through the API. The field's name becomes reusable once that pass completes, not before: registering it again in the meantime raises `FieldDeletionInProgressException` rather than silently handing you back the field being deleted. A field cannot be deleted while it is being renamed or retyped, and once deletion starts it cannot be renamed, retyped, promoted, demoted or compacted. There is no undelete.
- **Schema introspection** — `listModels()` and `describeModel()` report a tenant's models and each field's declared type, so a UI can render the schema without hand-written registry SQL. Every field reports both whether it is *declared* filterable and whether it is *currently* indexed — the two differ during a backfill, and gating on the latter is what stops a UI from offering a filter the engine would reject.
- **Slot maintenance** — `spread:report` shows how many extension pages each model's filterable fields occupy versus the fewest they could, so avoidable joins are visible before they cost you. `compact:model` acts on that: it relocates a fragmented model's fields onto a minimal page set, one field at a time so only one field is unfilterable at any moment, and `--dry-run` prints the plan without touching anything. Compaction declines to run — dry run included — while any field of the model is still being retyped, promoted, demoted or relocated, because a field mid-move has no settled location to plan around; wait for the Reconciler and re-run.

**Not yet available:**

- **Sorting accepts one key.** You can order by entry id, creation time, or a single indexed field. Ordering by two fields at once — "by status, then by name" — is not supported; a second key would need a different pagination protocol.
- **Exports cannot be filtered.** An export always covers every non-deleted entry in the model. A `submitExport()` call carrying a non-empty `filter` is **rejected** with `ExportFilterNotSupportedException` rather than accepted and quietly ignored, so you find out at submission instead of discovering a full extract in the artifact. The argument is kept on the request DTO so filtering can be added later without a breaking signature change.

The remaining build sequence toward the v0.3.0 GA contract is documented in the project's design notes (maintained separately). Each phase is a gate with explicit exit criteria.

If you need a working library today, stay on `^0.2.0-alpha.x`.

---

## Requirements

- **PHP:** 8.1 or later
- **PHP extensions:** `ext-pdo`, `ext-pdo_mysql`
- **Database:** MySQL 8.0.13+ **or** Percona Server 8.0.13+ **or** MariaDB 10.11+

The engine detects which one it's talking to at boot — there is no configuration flag to set. MySQL's floor is firm: StarDust leans on functional/conditional unique indexes, which don't exist below 8.0.13. MariaDB has no equivalent index type at any version; the same "at most one live slot per field" invariant is instead enforced there by a generated column plus a plain unique index, which is why MariaDB's own floor (10.11) was chosen independently rather than by mirroring MySQL's. We'd rather refuse to start than corrupt your registry on an engine that silently does the wrong thing.

**One documented behavioral difference on MariaDB:** range filters (`lt`, `lte`, `gt`, `gte`, and a `between` whose bounds straddle it) and field sorts order supplementary-plane Unicode characters — mostly emoji, well outside everyday text — at the opposite end from MySQL. Every other comparison, and ordinary text in any language, is unaffected.

**Not supported:**

- **MariaDB 10.6 and older** — a JSON-column collation divergence found below the 10.11 floor has no configuration-only fix.
- **MySQL 5.7 and older** — no partial-unique-index feature, which the schema registry depends on.

Either unsupported engine is detected and refused at boot, not discovered in production.

---

## Deployment Requirements

Two deployment modes: the reference mode (persistent processes, the MySQL floor, CLI access, artifact disk, and singleton enforcement for the Watcher), or one bounded `bin/stardust tick` on a schedule for a host with no persistent-process capability — plus which hosting tiers qualify for each: [docs/deployment.md](docs/deployment.md).

---

## Installation

```bash
composer require damarbob/stardust
```

The package's only runtime dependencies are `psr/log` and `psr/clock` (both interface-only packages). It does not pull in a framework, an ORM, a query builder, or a logging implementation.

---

## Complete example

A minimal end-to-end walkthrough: bootstrap the schema, define a model, make its fields filterable, write a few entries, filter, and page through results. (This is the same flow as [`docker/seed.php`](docker/seed.php).)

### 1 — Bootstrap

```php
use StarDust\Config\Config;
use StarDust\StarDust;

$pdo = new PDO('mysql:host=127.0.0.1;dbname=app', $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$engine = new StarDust(new Config(pdo: $pdo));
$engine->bootstrap(); // idempotent — safe to call on every deploy
```

### 2 — Define the model and its fields

`schemaBuilder()` registers the model and its fields — no raw `INSERT`s. It's get-or-create, so re-running is safe, and it returns the model id plus a field-name → id map.

```php
use StarDust\Schema\FieldDefinition;

$company = $engine->schemaBuilder()->createModel(tenantId: 1, name: 'company', fields: [
    new FieldDefinition('name',      'string', isFilterable: true),
    new FieldDefinition('employees', 'int',    isFilterable: true),
]);

$modelId = $company->modelId;
```

### 3 — Make the filterable fields queryable

Registering a field records *intent*; the field becomes filterable once its value lands in an indexed slot column.

**In a running deployment this is automatic.** The Watcher notices fields waiting on a slot and provisions pages indexed for them; the Reconciler then claims a slot for any registered filterable field it finds still unmapped while draining the sync queue. Write an entry touching the field and it becomes queryable a moment later, without you reserving anything.

The manual route below is for one-off setup — a seed script, a test fixture, or a deployment where you want the slot in place before the first write. A page is created with exactly the slot columns you name, and every one of them is indexed — so name the slots your filterable fields will use, then reserve one per field:

```php
use StarDust\Page\PageProvisioner;
use StarDust\Slot\SlotReserver;

// Provision a page carrying the two slots the filterable fields will use.
// The page is created with exactly these columns, each with its own
// composite (tenant_id, slot) index — the list may not be empty.
(new PageProvisioner($pdo, $engine->config()->clock, $engine->logger(), $engine->serverEngine()))
    ->provision(filterableSlots: ['i_str_01', 'i_int_01']);

// Reserve one slot per field (free → assigned). Reservation takes the
// lowest-numbered free slot of each type, so 'name' lands on i_str_01 and
// 'employees' on i_int_01 — exactly the slots we just indexed.
$reserver = new SlotReserver($pdo, $engine->config()->clock, $engine->logger());
$reserver->reserve($company->fieldId('name'));
$reserver->reserve($company->fieldId('employees'));
```

### 4 — Write entries

The same entry can be built two ways — pick whichever fits the caller. A
JSON/array envelope (`{tenantId, modelId, fields}`, camelCase) is handy when
entries arrive off a wire (CMS, HTTP body, queue); the typed constructor gives
you IDE validation. Both flow through the **identical** write path: field values
coerce the same way (same `UncoercibleSlotValueException`), and `tenant_id` is
validated (`>= 1`) at the boundary regardless of how the payload was built.

```php
use StarDust\Write\EntryPayload;

// Typed — IDE-validated:
$engine->write(new EntryPayload(tenantId: 1, modelId: $modelId,
    fields: ['name' => 'Acme Corp', 'employees' => 340]));

// From a PHP array — e.g. you already decoded a request body:
$engine->write(EntryPayload::fromArray([
    'tenantId' => 1,
    'modelId'  => $modelId,
    'fields'   => ['name' => 'Globex', 'employees' => 85],
]));

// From a raw JSON string — feed an HTTP request body straight in:
$body = '{"tenantId": 1, "modelId": ' . $modelId . ',
          "fields": {"name": "Initech", "employees": 510}}';
$engine->write(EntryPayload::fromJson($body));

// Bulk: a JSON array of envelopes straight into bulkWrite():
//   $engine->bulkWrite(EntryPayload::listFromJson($jsonArrayBody));
```

### 5 — Filter and paginate

```php
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Json\JsonFilterDecoder;
use StarDust\Read\EntryQuery;

// Build the filter — two equivalent ways (the JSON form is used below):
//
//  (a) Typed — IDE-validated:
//      $filter = LeafNode::local('employees', 'gt', 100);
//
//  (b) From a JSON wire payload — e.g. decoded straight from an HTTP
//      request. Returns the same FilterNode the typed form produces;
//      rejections carry a closed error code + RFC 6901 pointer.
$filter = (new JsonFilterDecoder($engine->config()->queryFilterLimits))->decode(
    '{"filter": {"op": "gt",
        "field": {"model": "company", "name": "employees"}, "value": 100}}'
);

// Fetch companies with more than 100 employees, 2 per page.
$page = $engine->read(new EntryQuery(
    tenantId:     1,
    modelId:      $modelId,
    filter:       $filter,
    selectFields: ['name', 'employees'],
    pageSize:     2,
));

foreach ($page->rows as $entry) {
    echo $entry->fields['name'] . ' — ' . $entry->fields['employees'] . "\n";
}
// Acme Corp — 340
// Initech — 510

// Page through to exhaustion (this dataset fits in one page, so
// nextCursor is null — the loop exits immediately after page 1).
$cursor = $page->nextCursor;
while ($cursor !== null) {
    $page   = $engine->read(new EntryQuery(
        tenantId: 1, modelId: $modelId, pageSize: 2, cursor: $cursor,
    ));
    foreach ($page->rows as $entry) { /* ... */ }
    $cursor = $page->nextCursor;
}
```

### 6 — Point read

```php
$firstId = $page->rows[0]->id;
$entry   = $engine->get(tenantId: 1, entryId: $firstId);
echo $entry?->fields['name']; // Acme Corp
```

---

## Construction & schema bootstrap

Constructing `StarDust`, `bootstrap()`, `schemaBuilder()`, the `listModels()` / `describeModel()` introspection pair, and the 46 optional `Config` parameters: [docs/configuration.md](docs/configuration.md).

## Writing entries

Single writes, chunked bulk ingest, async submission, and building payloads from JSON or array envelopes: [docs/writing-entries.md](docs/writing-entries.md).

## Updating and deleting entries

Why `updateEntry()` is a full replace rather than a patch, and why `deleteEntry()` returns `false` where `updateEntry()` throws: [docs/writing-entries.md#updating-and-deleting-entries](docs/writing-entries.md#updating-and-deleting-entries).

## Reading entries

Cursor-paginated reads, the AST filter shape, point reads, JSON-payload fallback, and sorting: [docs/reading-entries.md](docs/reading-entries.md).

## Searching with the JSON wire format

Decoding a JSON filter payload into the AST, the 13-code error taxonomy, the datetime-offset rule, and the normative JSON Schema: [docs/query-filter.md](docs/query-filter.md).

## Custom search drivers

`EntrySearchInterface`, the seven methods a driver implements, and how capability is declared to the pre-flight pipeline: [docs/custom-search-drivers.md](docs/custom-search-drivers.md).

## Changing a field's type or filterability

Retype, filterability promotion and demotion, renaming a field or model, removing a field, and deleting a model — with what is immediate and what needs a running Reconciler: [docs/schema-changes.md](docs/schema-changes.md).

## Async exports

Submitting and polling an export, the per-tenant active-job cap, and the Chronicler's claim, resume and failure semantics: [docs/exports.md](docs/exports.md).

---

## Slot maintenance

`spreadSampler()->report()`, `cardinalitySampler()->report()`, and `compactModel()` — the PHP entry points behind `spread:report`, `cardinality:report`, and `compact:model`: [docs/slot-maintenance.md](docs/slot-maintenance.md).

## Tracing a request through the logs

Supplying your own `correlation_id` and following it across process boundaries into background daemon events: [docs/observability.md](docs/observability.md).

## Errors

Every typed exception the engine throws, what triggers it, and how to handle a `QueryFilterValidationException` wire-format rejection: [docs/errors.md](docs/errors.md).

## CLI

The framework-neutral `bin/stardust` entry point — bootstrap, the four daemons, the bounded combined `tick` command for hosts with no persistent-process capability, DLQ replay, and the operator-initiated `spread:report` / `cardinality:report` / `compact:model` commands: [docs/cli.md](docs/cli.md).

---

## Testing

StarDust is covered by a smoke suite that runs against a **real MySQL or MariaDB** — no mocked databases. It skips cleanly when no test database is configured, so a fresh clone runs green out of the box:

```bash
composer install
cp phpunit.xml.dist phpunit.xml         # gitignored; edit with your DB creds
vendor/bin/phpunit --testsuite Smoke
```

A handful of the suite's tests need no database at all (e.g. the wire-format decoder, the event-vocabulary guard, and the schema-conformance cross-check), so they run even on a bare clone.

GitHub Actions runs the same suite on every push against MySQL and against MariaDB 10.11+ (both **must pass**), plus a job that asserts the suite **fails** against MariaDB 10.6, which is below the supported floor.

For the full setup guide and a phase-by-phase breakdown of exactly what each behaviour the suite proves, see **[TESTING.md](TESTING.md)**.

---

## Contributing

Bug reports, questions, and pull requests are welcome.

Start with **[CONTRIBUTING.md](CONTRIBUTING.md)** — it covers the requirements, the setup, and the three commands to run before pushing. Most of this project's conventions are enforced by tests rather than by review, so you will hear about a mistake immediately instead of days later.

---

## Legacy

The legacy 0.2.x source code has been removed from the repository; it remains available via the `^0.2.0-alpha.x` release tags on Packagist.

---

## License

MIT License.
