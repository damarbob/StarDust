# Runnable examples

Small, self-contained scripts that show StarDust behaviour a document
cannot: things that happen **over time**, across more than one process.

Each one seeds its own data, narrates what it is doing in the terminal,
and deletes everything it created on the way out. They are safe to
re-run and safe to interrupt.

They are not a tutorial for the API — [the README](../README.md) has a
complete worked example, and [docker/seed.php](../docker/seed.php) is a
copy-pasteable end-to-end script. These are for the parts that surprise
people.

## Setup

Any MySQL 8.0.13+ database you do not mind writing to. The quickest is
the one from the repo's Compose file:

```bash
docker compose up mysql -d
```

Then give the scripts three variables, either in a git-ignored `.env` at
the repo root:

```bash
STARDUST_DSN=mysql:host=127.0.0.1;port=3307;dbname=stardust
STARDUST_USER=root
STARDUST_PASS=root
```

...or as exports, which take precedence over the file:

```bash
export STARDUST_DSN='mysql:host=127.0.0.1;port=3307;dbname=stardust'
export STARDUST_USER=root
export STARDUST_PASS=root
```

Either way:

```bash
php examples/01-field-lifecycle.php
```

Same three variables as `bin/stardust`, so a shell already set up for
the CLI needs nothing further. Note that the `.env` shortcut is the
examples' own convenience — `bin/stardust` reads the process
environment only, and StarDust itself has no dotenv dependency.

**Point them at a database of their own.** These scripts seed thousands
of rows and call `deleteModel()`, which physically deletes `entry_data`
rows with no undo. Do not aim them at the smoke suite's database, or at
anything you would miss.

MariaDB will not work — the engine detects it and refuses to boot.

The scripts tick the daemons **in-process**, so you do not need to start
any. That is a teaching device, not how you would deploy: in production
the Watcher, Reconciler, Liberator and Chronicler are separate
long-running processes. Pass `--observe` to tick nothing and watch your
own daemons instead.

## 01 — the field lifecycle

```bash
php examples/01-field-lifecycle.php
```

**The question it answers:** you marked a field filterable, the call
returned successfully, and filtering on it still throws. Why, and for
how long?

This is comfortably the most common StarDust surprise. Marking a field
filterable records an *intention* and commits in a few milliseconds. The
work that makes filtering actually possible happens afterwards, in
background daemons, and until it finishes the field is in a state where
`describeModel()` reports `isFilterable: true` while every filter
against it is rejected.

The script runs in two acts. In Act 1 the promotion has been called and
nothing is ticking, so the frame sits frozen — which is exactly what an
install with no daemons running looks like, indefinitely. In Act 2 it
starts ticking a Watcher and a Reconciler, and the same five rows come
alive: a page is provisioned, a slot is reserved, a cursor climbs
through the existing rows, and the identical `read()` call stops
throwing and starts returning.

```text
  1  YOUR CALL      $engine->promoteFieldToFilterable(1, 42);
                    returned 19s ago — and has been finished ever since.

  2  THE REGISTRY   describeModel() on 'sustainability' (int):
                      isFilterable  YES   <- the intent you recorded
                      isIndexed      no   <- whether a filter works NOW

  3  THE SLOT       entry_slots_page_1.i_int_01
                    free -> assigned -> backfilling -> ready
                                        ^^^^^^^^^^^

  4  THE BACKFILL   checkpoint retype_field_42 is running
                    [###############.........]  62%   cursor id 2,480
                    2,480 of 4,000 rows now carry a value in the slot

  5  CAN I FILTER?  read(filter: LeafNode::local('sustainability', 'gte', 80))
                    NO — FieldNotFilterableException
```

Two behaviours worth knowing before you run it, because the script will
show you one or the other and they look quite different:

- **If your database has no free indexed slot** of the right type, the
  promotion cannot reserve one either. The Watcher must provision a page
  first, then the Reconciler reserves from it. Both daemons are needed.
- **If a free indexed slot already exists**, the promotion reserves it
  inside its own transaction and the Watcher is never involved. Only the
  row copy is deferred.

The first run against an empty database takes the first path; a second
run usually takes the second. The script says which one it took.

### Flags

| Flag | Default | What it does |
| :-- | :-- | :-- |
| `--rows=N` | 4000 | products to seed |
| `--chunk=N` | 200 | backfill rows per chunk |
| `--stall=N` | 8 | seconds to hold Act 1 |
| `--tick-ms=N` | 200 | frame interval |
| `--timeout=N` | 120 | give up after N seconds |
| `--tenant=N` | 1 | tenant id |
| `--observe` | off | tick nothing; watch your own daemons |
| `--keep` | off | skip the cleanup that deletes the demo model |
| `--no-colour` | off | plain output |

The backfill runs one chunk per frame, so `--rows` ÷ `--chunk` × `--tick-ms`
is roughly how long the middle states stay on screen. The defaults give
a few seconds; the production defaults would be over before the frame
could redraw, which is why the example does not use them.

`--observe` is the one to reach for when something in your own
application "didn't happen". Start your daemons, run it, and the daemon
rows at the bottom tell you which stage is stuck.

## A note on the exception you will see

In Act 1 the frame shows the real rejection, and it is
`FieldNotFilterableException` — "Field 'sustainability' is not
filterable on the active driver" — directly contradicting row 2, which
reports the same field as filterable.

Row 2 is right. The field is filterable; what it has not got yet is an
indexed slot. `FieldNotIndexedException` exists for exactly this case
and is what several docblocks say you will get, but nothing in the
engine currently throws it. Catch `FieldNotFilterableException` for now,
and do not read its message as authoritative about the registry.

## Terminal notes

The live frame needs a terminal at least 38 rows tall. Anything shorter,
or output that is piped or redirected, falls back to printing each state
transition as a line — the same information, without the live view. The
script says which mode it is in on startup.

Set `NO_COLOR=1` (or pass `--no-colour`) to drop ANSI colour.
`STARDUST_EXAMPLE_TTY=1` forces the live frame on even through a pipe,
which is useful for recording a demo.

## Adding an example

Keep the shape: one question in the title, a frame that a beginner can
read without knowing the vocabulary, its own seed data, and cleanup that
leaves nothing behind. `lib/Term.php` handles the rendering and the
append-mode fallback; `lib/EventRecorder.php` captures the engine's
events instead of letting them print over your frame.

These scripts are outside PHPStan's analysed paths (`src/` and `bin/`),
so they are not covered by the level-8 gate. Do not let that become an
excuse — they are read by people learning the engine, and code in them
is copied.
