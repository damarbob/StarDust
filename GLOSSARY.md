# Glossary

> Plain-language definitions of every StarDust term you will meet while using the library.
>
> If you are new here, read [How the pieces fit](#how-the-pieces-fit) first — it threads the core terms together in one pass. The [Terms](#terms) section below it is alphabetical, for looking things up later.

---

## How the pieces fit

A **tenant** is your isolation boundary — one customer, one workspace, one account, whatever that means in your app. Inside a tenant you define **models** (a shape, like "company" or "invoice"), and each model has **fields**. An **entry** is one record of a model.

When you write an entry, its complete data — every field, filterable or not — goes into a single JSON **payload** stored in the `entry_data` table. That payload is the **system of record**: it is always complete, always authoritative, and nothing else in the engine is allowed to disagree with it.

That alone would give you a document store you cannot query efficiently. So StarDust adds a second step: any field you mark **filterable** is *also* mirrored into a typed, indexed column — a **slot** — on an **extension page**, a side table joined 1:1 to `entry_data`. A filter on that field then reads a real B-tree index instead of scanning JSON. This split is the whole architecture, and it has a name: **vertical schema partitioning**.

Slots are a finite resource: a page is created with a fixed set of indexed columns and can never be widened afterwards. The **Watcher** daemon provisions fresh pages as capacity runs low, and **index headroom** makes each new page carry spare columns so a handful of fields promoted in sequence land together rather than scattering. When a field is *declared* filterable but has not been mirrored yet, it is not **indexed** yet either — and filtering it is refused up front by **pre-flight rejection** rather than degrading into a slow scan.

That gap between "I asked for it" and "it works" is the **backfill window**, and it is the single concept most worth internalising. Making a field filterable, retyping it, renaming it, or deleting it all return immediately and finish in the background — the **Reconciler** daemon does the catching up. During the window, reads keep working from the JSON payload; filters on the field in flight do not. Two more daemons round it out: the **Liberator** reclaims slots freed by demoted or deleted fields, and the **Chronicler** writes export files.

Over time a model's slots can end up scattered across more pages than they need — **spread** — which costs an extra join per filtered query. The engine reports it and you decide whether to pay for **compaction**.

---

## Terms

### Artifact

The file a completed export produces — CSV or JSON, written to the directory you configured. You serve or move it yourself; the [Chronicler](#chronicler) deletes it automatically once its TTL expires (24 hours by default), so treat the path as temporary.

**See also:** [Export job](#export-job).

### Backfill

The background pass that fills a slot column with values read out of already-stored JSON payloads. It runs in bounded chunks so it never locks up your database, and it is what turns a newly [filterable](#filterable) field into an [indexed](#indexed) one. Backfills are the [Reconciler](#reconciler)'s job, so nothing progresses without one running.

**See also:** [Backfill window](#backfill-window), [Reconciler](#reconciler).

### Backfill window

The interval between a schema operation returning and its background pass finishing — the period during which the change is *declared* but not yet fully *applied* to stored data. Every asynchronous operation has one: making a field filterable, [retyping](#retype) it, renaming it, deleting it, and deleting a model.

What still works during the window is deliberate and worth knowing: **reads keep returning the field**, served from the JSON [payload](#payload), and **writes keep being accepted**. What does not work is **filtering on the field in flight** — that is rejected with a typed exception rather than silently returning wrong results. The window closes when the [Reconciler](#reconciler) finishes; without one running it never closes at all.

**See also:** [Backfill](#backfill), [Pre-flight rejection](#pre-flight-rejection), [Indexed](#indexed).

### Bounded read

The two-query strategy behind every read and search. The first query finds only the matching entry IDs for one page, using indexes and a [cursor](#cursor) bound; the second fetches the full rows for just those IDs. The point is that the database never assembles a result set larger than your page size, so a query costs the same whether the tenant has a thousand entries or ten million.

**See also:** [Cursor](#cursor).

### Bulk ingestion

Writing many entries in one call. Batches are committed in chunks rather than as one giant transaction, which means a mid-batch failure leaves earlier chunks committed — you get a per-chunk report rather than all-or-nothing. Up to 1 000 entries runs inline and returns the result directly; beyond that you submit the batch, get a job handle back immediately, and poll it while the [Reconciler](#reconciler) works through it.

**See also:** [Idempotency key](#idempotency-key), [Reconciler](#reconciler).

### Chronicler

The daemon that turns export jobs into files. It claims pending jobs, pages through the data using the same [bounded read](#bounded-read) the synchronous API uses, streams CSV or JSON to disk, and cleans up expired [artifacts](#artifact). Multiple copies can run at once for throughput.

**See also:** [Export job](#export-job), [Daemon](#daemon).

### Compaction

The operator-initiated repack that cures [spread](#spread): it relocates a model's filterable fields onto the fewest pages that can hold them, removing avoidable joins. It is deliberately slow and manual — it moves one field at a time, needs a running [Reconciler](#reconciler), and blocks until each move lands, so never call it from a request path. A dry run prints the plan without touching anything, and re-running after a crash is safe because fields already in place are skipped.

**See also:** [Spread](#spread), [Backfill window](#backfill-window).

### Correlation ID

An identifier that ties every log event belonging to one operation together, including events a [daemon](#daemon) emits minutes later in a different process. The engine generates one per operation, but you can pass your own — your HTTP request ID, typically — so a support ticket quoting a single request ID is answerable end to end.

### Cursor

The opaque token that marks your position in a paginated read. You pass the previous page's cursor to get the next one, and the absence of a cursor means you have reached the end. There is deliberately no offset parameter and no total count: both would require the database to read the entire matching set, so a query that is fast today would slow down purely because the tenant grew. Infinite scroll and a Next button work naturally; "Page 7 of 214" cannot be served.

**See also:** [Bounded read](#bounded-read), [Sort](#sort).

### Daemon

A long-lived background process you run alongside your app — StarDust ships four ([Watcher](#watcher), [Reconciler](#reconciler), [Liberator](#liberator), [Chronicler](#chronicler)), all launched through `bin/stardust`. They never talk to each other directly; the database is the only thing they coordinate through. They are not optional extras: without them, capacity is never replenished and background work never completes.

### Declared type

The type you register a field as — `string`, `int`, `numeric`, or `datetime`. It decides which family of [slot](#slot) column the field can occupy and how filter values against it are validated. Changing it later is a [retype](#retype).

### Dead-letter queue

Where the [Reconciler](#reconciler) parks an individual record it could not process, so one bad row cannot wedge the whole queue. Nothing is retried automatically and nothing expires on its own — this is a queue you are expected to look at. Once you have fixed the underlying cause, a CLI command replays rows back into the work queue, either one by one or by failure reason.

**See also:** [Reconciler](#reconciler), [Sync queue](#sync-queue).

### Demotion

Marking a previously filterable field as non-filterable. It takes effect immediately — reads fall straight back to the JSON [payload](#payload) and filters on the field start being rejected — and its [slot](#slot) is [tombstoned](#tombstoned-slot) for the [Liberator](#liberator) to reclaim. Unlike [promotion](#promotion), there is no waiting period, because nothing has to be built.

**See also:** [Promotion](#promotion), [Filterable](#filterable).

### Entry

One record of a [model](#model), belonging to one [tenant](#tenant). Physically it is always one row in `entry_data` holding the complete [payload](#payload), plus — if the model has filterable fields with slots — one mirrored row on each [extension page](#extension-page) those slots live on.

**See also:** [Model](#model), [Payload](#payload).

### Exhaustion fallback

What happens when you write a filterable field and no [slot](#slot) capacity is available: the value still lands in the JSON [payload](#payload), the entry is queued for later mirroring, and the write succeeds. The design choice here is that **writes never fail or block for lack of index capacity** — they degrade to unindexed until the [Watcher](#watcher) provisions a page and the [Reconciler](#reconciler) catches up. The cost is that the affected entries are temporarily invisible to filters on that field.

**See also:** [Sync queue](#sync-queue), [Watcher](#watcher).

### Export job

A request to dump a model's entries to a file, handled asynchronously so a large extract never ties up a request. You submit it, get an ID back, and poll until it reports completed or failed. Exports cover the whole model — a filtered export is rejected at submission rather than quietly ignored — and each tenant may have a limited number in flight at once (three by default).

**See also:** [Chronicler](#chronicler), [Artifact](#artifact).

### Extension page

A side table, physically named `entry_slots_page_1`, `entry_slots_page_2` and so on, holding the indexed [slot](#slot) columns that mirror filterable field values. Each row lines up 1:1 with a row in `entry_data`. A page is created with a fixed set of indexed columns and **can never be altered afterwards** — that immutability is what keeps provisioning safe on a live database, and it is also why pages are created with [index headroom](#index-headroom) rather than exactly as many columns as are needed at that instant.

**See also:** [Slot](#slot), [Watcher](#watcher), [Spread](#spread).

### Field

One named attribute of a [model](#model). A field has a [declared type](#declared-type) and a [filterable](#filterable) flag, and that flag is the entire difference between "stored and readable" and "stored, readable, and queryable at index speed". Fields can be renamed, retyped, promoted, demoted, and deleted while the system is live.

**See also:** [Filterable](#filterable), [Retype](#retype), [Declared type](#declared-type).

### Filterable

The flag that decides whether a field gets a [slot](#slot). A filterable field is mirrored into an indexed column and can be used in filters and sorts; a non-filterable field lives in the JSON [payload](#payload) only — still written, still returned by reads, just not queryable. Non-filterable is the cheaper default: it costs no slot and no index maintenance.

Note that declaring a field filterable and it *being* queryable are two different moments — see [Indexed](#indexed).

**See also:** [Indexed](#indexed), [Promotion](#promotion), [Slot](#slot).

### Filter tree

The structure a filter takes: individual conditions (field, operator, value) combined with AND, OR, and NOT into a tree of any depth. You can build one directly in PHP, or hand the engine [QueryFilter](#queryfilter) JSON and have it decoded for you. The built-in operator vocabulary is deliberately small — equality, comparison, range, set membership, null checks, and anchored prefix matching — with no substring, fuzzy, or relevance-ranked matching. A custom [search driver](#search-driver) may declare operators of its own beyond that set.

**See also:** [QueryFilter](#queryfilter), [Search driver](#search-driver).

### Idempotency key

An optional string you attach to an asynchronous bulk submission so that retrying it — after a dropped connection, say — returns the original job instead of ingesting everything twice. Keys are unique per [tenant](#tenant); submissions without one never collide with anything.

**See also:** [Bulk ingestion](#bulk-ingestion).

### Index headroom

The number of spare indexed columns of each type built into every new [extension page](#extension-page), configurable and four by default. It exists because pages cannot be widened after creation: without spare columns, three fields promoted one after another can each trigger a fresh page and end up on three of them, which costs a join per page on every query touching all three. With headroom, a fresh page carries enough spare capacity that the next few promotions land together. Raising the setting only affects pages created from that point on.

**See also:** [Extension page](#extension-page), [Spread](#spread).

### Indexed

Whether a field's [slot](#slot) is live *right now* — that is, whether a filter on it would actually work. This is distinct from being declared [filterable](#filterable), and the two disagree for the whole of a [backfill window](#backfill-window). Schema introspection reports both, and gating your UI on this one is what stops it offering a filter the engine would reject.

**See also:** [Filterable](#filterable), [Backfill window](#backfill-window), [Pre-flight rejection](#pre-flight-rejection).

### Liberator

The daemon that reclaims [slots](#slot). When a field is demoted or deleted, its slot is [tombstoned](#tombstoned-slot) rather than handed straight to the next field — the old values are still sitting in the column. The Liberator clears them out in bounded chunks and only then marks the slot free, so a recycled slot can never leak a previous field's data into a new one's queries.

**See also:** [Tombstoned slot](#tombstoned-slot), [Daemon](#daemon).

### Model

A named data shape within a [tenant](#tenant) — the rough equivalent of a table, except you define it at runtime rather than in a migration. A model owns a set of [fields](#field), and every [entry](#entry) belongs to exactly one model. Models can be renamed (instantly, since nothing resolves a model by name) and deleted (in the background, and irreversibly).

**See also:** [Field](#field), [Entry](#entry), [Tenant](#tenant).

### Payload

The complete JSON object holding all of an [entry](#entry)'s field values, stored in `entry_data`. Every field is here, filterable or not — the indexed [slots](#slot) are a *copy* of some of these values, never the original. This is the [system of record](#system-of-record), which is why a field with no slot is still fully readable and why a failed backfill can never lose data.

**See also:** [System of record](#system-of-record), [Slot](#slot).

### Pre-flight rejection

The engine's policy of refusing an impossible query at the API boundary, before the database is touched at all. Filtering or sorting on a field that is unknown, non-[filterable](#filterable), or not yet [indexed](#indexed) raises a typed exception immediately rather than running a query that would table-scan. This is a deliberate trade: you get a loud, fast, catchable error instead of a query that appears to work and then collapses as the tenant grows.

**See also:** [Indexed](#indexed), [Filterable](#filterable).

### Promotion

Turning a non-filterable field into a filterable one on a live system. A [slot](#slot) is reserved and the existing entries' values are copied into it by the [Reconciler](#reconciler); until that finishes the field is in its [backfill window](#backfill-window) — readable, writable, not yet filterable. This is the operation behind the most common surprise in StarDust: a field you just marked filterable is not filterable *yet*.

**See also:** [Backfill window](#backfill-window), [Demotion](#demotion), [Filterable](#filterable).

### QueryFilter

The JSON wire format for filters, so a filter can travel from a browser or an API client and be validated before it reaches your database. Decoding is strict and failures are precise: each rejection carries a machine-readable error code, a pointer to the exact offending node in your JSON, and the specifics of the mismatch — enough to render a useful HTTP 400 without writing a handler per failure mode.

**See also:** [Filter tree](#filter-tree), [Pre-flight rejection](#pre-flight-rejection).

### Reconciler

The daemon that does the catching up. It drains every kind of deferred work the engine produces — entries queued during an [exhaustion fallback](#exhaustion-fallback), asynchronous bulk imports, [promotion](#promotion) and [retype](#retype) backfills, rename rewrites, and deletion purges — in bounded chunks. Multiple copies can run at once, and they divide the work without stepping on each other. Nearly every [backfill window](#backfill-window) in this glossary closes because a Reconciler closed it.

**See also:** [Backfill window](#backfill-window), [Dead-letter queue](#dead-letter-queue), [Daemon](#daemon).

### Retype

Changing a field's [declared type](#declared-type) on a live system — `string` to `int`, say. The existing values are converted from the JSON [payload](#payload) into a fresh [slot](#slot) of the new type in the background. Values that cannot be converted (`"42abc"` to an integer) become null *in the slot only* — the payload keeps the original, so reads still show it and nothing is lost. Conversions between `int`/`numeric` and `datetime` are refused outright, because there is no interpretation of a bare number as a date that the engine can pick for you; convert via `string` if you need one.

**See also:** [Declared type](#declared-type), [Backfill window](#backfill-window).

### Schema registry

The set of `stardust_`-prefixed tables recording your models, fields, pages, and slot assignments. It is the engine's own bookkeeping — you create it once with `bootstrap` and then read it through the introspection API rather than querying it directly. It is also the only channel the [daemons](#daemon) use to coordinate; there is no message bus and no direct process-to-process communication.

**See also:** [Schema version](#schema-version).

### Schema version

A single counter bumped every time coordination-relevant registry state changes. The engine caches schema lookups on the read path and uses this counter to know when its cache is stale, which is what lets a live schema change be picked up without restarting your application.

**See also:** [Schema registry](#schema-registry).

### Search driver

The swappable component that actually executes a filtered read. The default runs native MySQL against the indexed [slots](#slot); you can inject your own to delegate to an external search service instead. Drivers are read-only — writes always go to MySQL — and each one declares its own capabilities, so if you ask for something the active driver cannot do, you get a typed rejection rather than a wrong answer.

**See also:** [Filter tree](#filter-tree), [Pre-flight rejection](#pre-flight-rejection).

### Slot

One typed, indexed column on an [extension page](#extension-page) that mirrors one filterable field's value. Slots come in four families matching the [declared types](#declared-type) — string, integer, numeric, datetime — and a field can only occupy a slot of its own family, which is why a [retype](#retype) needs a fresh slot rather than converting in place. A page holds a bounded number of each family, so slots are the engine's finite resource and the thing capacity planning is about.

String slots hold long values (filter bounds accept up to 4 096 characters) and are matched exactly despite the index covering only a prefix of each value.

**See also:** [Extension page](#extension-page), [Filterable](#filterable), [Tombstoned slot](#tombstoned-slot).

### Soft deletion

How deleting an *entry* works: a timestamp is set rather than the row removed, after which the entry is gone from reads, filters, point reads, and exports alike. Deleting a [model](#model) is the one operation that genuinely destroys rows — entries, mirrored slot rows, and the model's own definition — and there is no undelete, so export first if you might want the data back.

**See also:** [Entry](#entry), [Model](#model).

### Sort

The ordering of a read. You may sort by one key: entry ID, creation time, or a single [indexed](#indexed) field. The first two walk an existing index and stay cheap at any page depth; sorting by a field costs a full sort pass over the matching set on every page, which is still bounded but not free. Sorting by two fields at once is not supported. A field must be indexed to be sortable, for the same reason it must be indexed to be filtered.

**See also:** [Cursor](#cursor), [Indexed](#indexed).

### Spread

How many [extension pages](#extension-page) a single model's filterable [slots](#slot) are scattered across. It matters because every extra page a filtered query touches costs another join. Spread is not a bug — it is the natural consequence of pages being immutable and slots being handed out from whichever page has room — and a model that grew field by field over a year can easily end up spread.

The engine measures it as *excess pages*: the pages a model actually occupies minus the fewest it could fit on. The report is purely advisory and never changes anything on its own; acting on it is [compaction](#compaction), which you choose to run.

**See also:** [Compaction](#compaction), [Index headroom](#index-headroom), [Extension page](#extension-page).

### Sync queue

The small table holding entries whose indexed mirroring was deferred, almost always because of an [exhaustion fallback](#exhaustion-fallback). A row here means "this entry's payload is correct but its slot columns are not yet". The [Reconciler](#reconciler) drains it and deletes each row on success; a persistently growing queue means the Reconciler is not running, or the [Watcher](#watcher) is not keeping up with capacity.

**See also:** [Exhaustion fallback](#exhaustion-fallback), [Reconciler](#reconciler).

### System of record

The authoritative copy of your data — in StarDust, always the JSON [payload](#payload) in `entry_data`, never the indexed [slots](#slot). The slots are a queryable mirror that the engine is free to rebuild, null out, or relocate at any time. This ordering is what makes every other guarantee here safe: a coercion that fails, a backfill that has not run, and a slot being reclaimed all affect what is *queryable*, never what is *stored*.

**See also:** [Payload](#payload), [Slot](#slot).

### Tenant

The top-level isolation boundary. Every query the engine builds carries the tenant ID on every `WHERE` and `JOIN`, so one tenant's data is not merely filtered out of another's results — it is unreachable at the SQL level. All models, fields, and entries live inside exactly one tenant.

**See also:** [Model](#model), [Entry](#entry).

### Tombstoned slot

A [slot](#slot) whose field is gone — demoted or deleted — but whose old values are still physically in the column. A tombstoned slot is not reusable: handing it to a new field while the previous occupant's data sat there would let the new field's filters match values nobody ever wrote to it. The [Liberator](#liberator) clears the column and only then returns the slot to the free pool.

**See also:** [Liberator](#liberator), [Demotion](#demotion).

### Vertical schema partitioning

The architecture behind StarDust, and the thing that distinguishes it from both a document store and an EAV table: each entry is split across a complete JSON [payload](#payload) for storage and separate indexed [slot](#slot) columns for querying. Storage stays schemaless and cheap; querying gets real B-tree indexes and a fixed, small number of joins. The alternative designs fail in opposite directions — a single wide table cannot absorb per-tenant fields, and an attribute-per-row table collapses under self-joins.

**See also:** [Payload](#payload), [Slot](#slot), [Extension page](#extension-page).

### Watcher

The daemon that keeps [slot](#slot) capacity ahead of demand. It provisions new [extension pages](#extension-page) when free capacity drops below a threshold (20% by default) or when a filterable field is waiting for a slot that does not exist yet, and it indexes each new page for the fields currently queued. Exactly one Watcher may run at a time, and it enforces that itself. Without it, capacity is never replenished and filterable writes quietly fall back to unindexed JSON.

**See also:** [Extension page](#extension-page), [Exhaustion fallback](#exhaustion-fallback), [Index headroom](#index-headroom).
