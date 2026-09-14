# CLI

The framework-neutral CLI entry point is `bin/stardust`:

```bash
vendor/bin/stardust --version
vendor/bin/stardust --help

# Phase 1: idempotently bootstrap the schema on a configured database.
# Reads STARDUST_DSN / STARDUST_USER / STARDUST_PASS from the environment.
STARDUST_DSN='mysql:host=127.0.0.1;dbname=app' \
STARDUST_USER=root STARDUST_PASS=root \
vendor/bin/stardust bootstrap

# Phase 5: singleton page-provisioning daemon. Holds a flock on
# <pidFileDir>/watcher.pid; a second instance exits with code 2.
vendor/bin/stardust watcher

# Phase 5: multi-worker sync_queue + import_jobs drain. Run as many
# replicas as you need — SKIP LOCKED keeps them disjoint.
vendor/bin/stardust reconciler

# Phase 5: operator-initiated DLQ replay (re-enqueues into
# stardust_sync_queue and removes the DLQ row in one transaction).
vendor/bin/stardust reconciler:dlq:replay --id=42
vendor/bin/stardust reconciler:dlq:replay --reason=schema_incompatibility

# Phase 6a: singleton slot-reclamation daemon. Polls
# stardust_slot_assignments for `tombstoned` rows, nullifies the
# corresponding slot column on entry_slots_page_N in bounded chunks,
# and transitions the slot back to `free` once the partition is
# fully nullified. Holds a flock on <pidFileDir>/liberator.pid; a
# second instance exits with code 2.
vendor/bin/stardust liberator

# Phase 7: multi-worker async export daemon. Claims pending or
# abandoned export jobs from stardust_export_jobs, paginates
# entry_data, streams CSV/JSON artifacts to <artifactDir>, runs
# idle-cycle GC on completed-artifact TTL + orphaned failed-job
# partials. Run multiple processes for horizontal scale — no PID
# guard; SELECT … FOR UPDATE SKIP LOCKED is the only coordination
# primitive.
vendor/bin/stardust chronicler

# Report slot spread: how many extension pages each model's filterable
# fields are scattered across, versus the fewest they could occupy.
# EXCESS is the number of avoidable joins every filtered query on that
# model pays — 0 is optimal packing. Registry-only and read-only, so it
# is safe to run against production at any time.
vendor/bin/stardust spread:report
vendor/bin/stardust spread:report --tenant=1 --model=7

# Compact a model: relocate its filterable fields onto the fewest pages
# that can hold them, removing the avoidable joins spread:report shows.
# Long-running and deliberate — it moves one field at a time and needs a
# running reconciler. While a field is in flight, filters on THAT field
# are rejected; reads keep working, and every other field is unaffected.
# Safe to re-run: fields already in place are skipped.
#
# A model cannot be compacted — not even with --dry-run — while one of
# its fields is still being retyped, promoted, demoted or relocated.
# Mid-move, a field's storage location is not yet settled, so any plan
# would report page counts that disagree with spread:report. Wait for
# the reconciler to finish and re-run; spread:report stays available.
vendor/bin/stardust compact:model --tenant=1 --model=7 --dry-run
vendor/bin/stardust compact:model --tenant=1 --model=7
```

Daemons honour both `SIGTERM`/`SIGINT` (when `ext-pcntl` is loaded) and
`touch <pidFileDir>/<daemon-name>.shutdown` as a graceful-shutdown
signal — useful on hosts without `pcntl`. Exit codes: `0` clean
shutdown (including signal-induced), `1` fatal, `2` singleton
violation or user error.
