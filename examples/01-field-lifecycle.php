<?php

declare(strict_types=1);

/**
 * Example 1 — what happens after `promoteFieldToFilterable()` returns.
 *
 *     php examples/01-field-lifecycle.php
 *
 * StarDust's most confusing behaviour, by a wide margin, is that
 * marking a field filterable records an *intention* and returns
 * immediately, while the work that makes filtering actually possible
 * happens later, in two different background daemons. Until both have
 * run, a filter on that field throws — and nothing in the calling code
 * gives any hint of why.
 *
 * That is a sequence in time, so no amount of prose shows it. This
 * script does, in two acts:
 *
 *   ACT 1  The promotion has been called. No daemon is running. The
 *          frame is frozen: filterable YES, indexed no, no slot, cursor
 *          at zero, filter throwing. This is the state people file bugs
 *          about.
 *   ACT 2  The same script starts ticking a Watcher and a Reconciler
 *          in-process. The frame comes alive: a page is provisioned, a
 *          slot is reserved, the cursor climbs, and the identical
 *          `read()` call stops throwing and starts returning rows.
 *
 * Flags:
 *   --rows=N        products to seed          (default 4000)
 *   --chunk=N       backfill rows per chunk   (default 200)
 *   --stall=N       seconds to hold Act 1     (default 8)
 *   --tick-ms=N     frame interval            (default 200)
 *   --timeout=N     give up after N seconds   (default 120)
 *   --tenant=N      tenant id                 (default 1)
 *   --observe       do NOT tick anything — use your own running daemons
 *   --keep          skip the cleanup that deletes the demo model
 *   --no-colour     plain output
 *
 * The chunk size is deliberately small so the backfill is watchable.
 * Production defaults drain far faster than this frame can redraw.
 */

require __DIR__ . '/bootstrap.php';

use StarDust\Config\Config;
use StarDust\Examples\EventRecorder;
use StarDust\Examples\LifecycleProbe;
use StarDust\Examples\LifecycleView;
use StarDust\Examples\Term;
use StarDust\Schema\FieldDefinition;
use StarDust\StarDust;
use StarDust\Write\BulkIngestOptions;
use StarDust\Write\EntryPayload;

$flags = stardust_example_flags($argv);

$rows      = stardust_example_int($flags, 'rows', 4000);
$chunk     = stardust_example_int($flags, 'chunk', 200);
$stallSecs = stardust_example_int($flags, 'stall', 8);
$tickMs    = stardust_example_int($flags, 'tick-ms', 200);
$timeout   = stardust_example_int($flags, 'timeout', 120);
$tenantId  = stardust_example_int($flags, 'tenant', 1);
$observe   = isset($flags['observe']);
$keep      = isset($flags['keep']);

$term = new Term(forceNoColour: isset($flags['no-colour']) || isset($flags['no-color']));

$pdo      = stardust_example_pdo();
$recorder = new EventRecorder();

// One deliberate departure from the defaults: a small backfill chunk,
// so the copy takes long enough to watch. Everything else is stock.
$engine = new StarDust(new Config(
    pdo:                        $pdo,
    logger:                     $recorder,
    watcherPollIntervalSeconds: 1,
    reconcilerChunkSize:        $chunk,
));

$term->note('');

$declined = $term->repaintDeclinedReason();
if ($declined !== null) {
    $term->note("Note: {$declined}.");
    $term->note('');
}

$term->note('Bootstrapping schema (idempotent)...');
$engine->bootstrap();

// ---------------------------------------------------------------------
// Seed. A fresh model per run, so the script is re-runnable and its
// cleanup can delete everything it made without touching your data.
// ---------------------------------------------------------------------

$modelName = 'lifecycle_demo_' . date('Ymd_His');

// Every field starts NON-filterable, including the one being promoted.
// That leaves the engine with zero pending demand, so Act 1's frozen
// frame is frozen for exactly one reason rather than several.
$model = $engine->schemaBuilder()->createModel($tenantId, $modelName, [
    new FieldDefinition('sku', 'string'),
    new FieldDefinition('name', 'string'),
    new FieldDefinition('sustainability', 'int'),
]);

$modelId = $model->modelId;
$fieldId = $model->fieldId('sustainability');

$term->note("Model '{$modelName}' created (id {$modelId}).");
$term->note("Seeding {$rows} products...");

$seedStarted = microtime(true);
$batch       = [];

for ($i = 1; $i <= $rows; $i++) {
    $batch[] = new EntryPayload(
        tenantId: $tenantId,
        modelId:  $modelId,
        fields:   [
            'sku'            => sprintf('SKU-%06d', $i),
            'name'           => 'Product ' . $i,
            'sustainability' => $i % 100,
        ],
    );

    // `bulkWrite()` is the synchronous path and refuses more than 1000
    // payloads per call — over that the API you want is
    // `submitBulkWrite()`, which queues an async import job instead.
    if (count($batch) === 500 || $i === $rows) {
        $engine->bulkWrite($batch, new BulkIngestOptions(chunkSize: 500));
        $batch = [];
        $term->note(sprintf('  %s / %s seeded', Term::num($i), Term::num($rows)));
    }
}

$term->note(sprintf('Seeded in %.1fs.', microtime(true) - $seedStarted));
$term->note('');

// ---------------------------------------------------------------------
// The call under the microscope.
// ---------------------------------------------------------------------

$calledAt = microtime(true);
$engine->promoteFieldToFilterable($tenantId, $fieldId);
$callMs = (microtime(true) - $calledAt) * 1000;

$term->note(sprintf(
    'promoteFieldToFilterable(%d, %d) returned in %.1f ms.',
    $tenantId,
    $fieldId,
    $callMs,
));
$term->note('');

// Whether the promotion found free indexed capacity is not a detail —
// it decides whether the Watcher is involved at all. With a free
// indexed slot of the right family already provisioned, `RetypeInitiator`
// reserves it inside the promotion's own transaction and only the row
// copy is deferred; with none, the reservation defers too and the
// Watcher has to provision a page first. The script narrates whichever
// one actually happened rather than assuming the longer path.
$probe = new LifecycleProbe(
    engine:         $engine,
    pdo:            $pdo,
    tenantId:       $tenantId,
    modelId:        $modelId,
    fieldId:        $fieldId,
    fieldName:      'sustainability',
    probeOperator:  'gte',
    probeValue:     80,
);

$view = new LifecycleView(
    term:            $term,
    tenantId:        $tenantId,
    fieldId:         $fieldId,
    probeExpression: "read(filter: LeafNode::local('sustainability', 'gte', 80))",
);

$reservedInCall    = $probe->snapshot()->slotStatus !== null;
$provisionObserved = false;

$term->note($reservedInCall
    ? 'A free indexed slot already existed, so the reservation happened'
      . ' inside that call. Only the row copy is deferred.'
    : 'No free indexed slot existed, so the reservation deferred too.'
      . ' The Watcher has to provision a page first.');
$term->note('');

$watcher    = $engine->watcher();
$reconciler = $engine->reconciler();

$watcherTicks    = 0;
$reconcilerTicks = 0;
$lastWatcherTick = 0.0;
$lastFingerprint = null;

// Observe mode has no Act 1: this script ticks nothing, so the frame is
// moved by the daemons the operator started, or by nothing at all —
// which is precisely the diagnostic it exists to make.
$act        = $observe ? 2 : 1;
$actCaption = $observe ? 'watching your daemons' : 'no daemon is running';

$succeeded = false;
$startedAt = microtime(true);
$deadline  = $startedAt + $timeout;

while (true) {
    $now     = microtime(true);
    $elapsed = $now - $startedAt;

    if ($act === 1 && $elapsed >= $stallSecs) {
        $act        = 2;
        $actCaption = 'ticking Watcher + Reconciler in-process';
        $term->note($term->cyan('--- ACT 2: starting the daemons ---'));
    }

    if (! $observe && $act === 2) {
        // The Watcher's real cadence is a poll interval, not a busy
        // loop; ticking it once a second keeps the frame honest about
        // that without making provisioning look instantaneous.
        if ($now - $lastWatcherTick >= 1.0) {
            $watcher->tick();
            $watcherTicks++;
            $lastWatcherTick = $now;
        }

        $reconciler->tick();
        $reconcilerTicks++;
    }

    $snapshot = $probe->snapshot();

    // Surface the interesting engine events as permanent lines. In
    // repaint mode they scroll above the frame; in append mode they are
    // the output.
    foreach ($recorder->drain() as $event) {
        if ($event['event'] === 'provision_complete') {
            $provisionObserved = true;
            $columns = $event['context']['indexed_columns'] ?? [];
            $term->note(sprintf(
                '  %s  watcher provisioned a page with indexed columns: %s',
                Term::clock($elapsed),
                is_array($columns) ? implode(', ', array_map('strval', $columns)) : '?',
            ));
        }
    }

    $fingerprint = $snapshot->fingerprint();
    if ($fingerprint !== $lastFingerprint) {
        if ($lastFingerprint !== null) {
            $term->note(sprintf(
                '  %s  %s',
                Term::clock($elapsed),
                describe_transition($snapshot),
            ));
        }
        $lastFingerprint = $fingerprint;
    }

    $term->paint($view->render(
        $snapshot,
        $act,
        $actCaption,
        $elapsed,
        $observe || $act === 2,
        $observe ? -1 : $watcherTicks,
        $observe ? -1 : $reconcilerTicks,
    ));

    if ($snapshot->isIndexed && $snapshot->filterWorks() && $act === 2) {
        $succeeded = true;
        break;
    }

    if ($now >= $deadline) {
        break;
    }

    usleep($tickMs * 1000);
}

$term->release();

if (! $succeeded) {
    echo "\n";
    echo "  Gave up after {$timeout}s — the field never became indexed.\n";
    echo "\n";

    if ($observe) {
        echo "  In --observe mode this script ticks nothing, so this almost\n";
        echo "  always means a daemon is not actually running. Start them:\n";
        echo "\n";
        echo "      php bin/stardust watcher\n";
        echo "      php bin/stardust reconciler\n";
        echo "\n";
        echo "  That diagnosis is the whole point of --observe: the engine\n";
        echo "  gives no error for a missing daemon, because nothing has\n";
        echo "  failed. The work is simply never picked up.\n";
    } else {
        echo "  That is unexpected — the script was ticking both daemons\n";
        echo "  itself. Try a longer --timeout, or a smaller --rows.\n";
    }

    // Deliberately no cleanup: `deleteModel()` refuses while a retype
    // checkpoint is running, so attempting it here would replace a clear
    // diagnosis with an unrelated exception.
    echo "\n";
    echo "  Left in place for inspection: model '{$modelName}' (id {$modelId}),\n";
    echo "  with a running checkpoint 'retype_field_{$fieldId}'. Delete it once\n";
    echo "  a Reconciler has drained that checkpoint.\n\n";

    exit(1);
}

echo "\n";
echo "  The read() call never changed. The engine caught up to it.\n";
echo "\n";
echo "  What you just watched, in order:\n";

$step = 1;
printf("    %d. promoteFieldToFilterable() committed a registry flag and\n", $step++);
echo "       returned in " . sprintf('%.1f', $callMs) . " ms.\n";

if ($provisionObserved) {
    printf("    %d. The Watcher noticed a filterable field with no slot and\n", $step++);
    echo "       provisioned a page carrying an indexed integer column.\n";
    printf("    %d. The Reconciler reserved one of that page's slots, moving\n", $step++);
    echo "       it to 'backfilling'.\n";
} elseif ($reservedInCall) {
    printf("    %d. The slot was reserved inside that same call — free indexed\n", $step++);
    echo "       capacity already existed, so the Watcher had nothing to do.\n";
} else {
    printf("    %d. The Reconciler reserved an already-provisioned free slot,\n", $step++);
    echo "       moving it to 'backfilling'.\n";
}

printf("    %d. The Reconciler copied every existing product's value into\n", $step++);
echo "       that column, {$chunk} rows per chunk, advancing a checkpoint.\n";
printf("    %d. On the final chunk the slot flipped to 'ready' — and only\n", $step++);
echo "       then did describeModel() report isIndexed = true.\n";
echo "\n";
echo "  Skipping any daemon stops this at whichever step needs it.\n";
echo "\n";

// ---------------------------------------------------------------------
// Cleanup — itself a demonstration: deleteModel() is asynchronous too.
// ---------------------------------------------------------------------

if ($keep) {
    echo "  --keep: leaving model '{$modelName}' (id {$modelId}) in place.\n\n";
    exit(0);
}

echo "  Cleaning up: deleteModel() severs the model, then the Reconciler\n";
echo "  purges its entries. Same asynchronous shape as the promotion.\n";

$engine->deleteModel($tenantId, $modelId);

if ($observe) {
    echo "  --observe: your Reconciler will finish the purge.\n\n";
    exit(0);
}

$purgeDeadline = microtime(true) + 120;

// The Liberator is the fourth daemon, and this is the one place the
// example needs it: deleting the model tombstoned the slot the
// promotion had just won, and only the Liberator returns a tombstoned
// slot to `free`. Without it every run of this script would strand a
// slot and the Watcher would provision a fresh page for the next one.
$liberator = $engine->liberator();

while (model_row_exists($pdo, $modelId)) {
    $reconciler->tick();

    if (microtime(true) > $purgeDeadline) {
        echo "  Purge did not finish in 120s; a running Reconciler will complete it.\n\n";
        exit(0);
    }
}

echo "  Purged. Reclaiming the tombstoned slot (Liberator)...\n";

// A handful of ticks: the sweep nullifies the column in chunks and
// flips the slot back to `free` once it has walked the whole page.
for ($i = 0; $i < 20 && tombstoned_slots_exist($pdo); $i++) {
    $liberator->tick();
}

echo "  Done. Nothing this script created remains.\n\n";

/**
 * A one-line, beginner-readable account of what just changed.
 */
function describe_transition(\StarDust\Examples\LifecycleSnapshot $s): string
{
    if ($s->filterWorks() && $s->isIndexed) {
        return 'isIndexed is now true — the filter returns rows';
    }
    if ($s->slotStatus === 'ready') {
        return 'slot promoted to ready (final chunk landed)';
    }
    if ($s->slotStatus === 'backfilling') {
        return "slot {$s->slotColumn} reserved, status backfilling";
    }
    if ($s->slotStatus === 'assigned') {
        return "slot {$s->slotColumn} assigned";
    }

    return 'state changed';
}

function tombstoned_slots_exist(PDO $pdo): bool
{
    $stmt = $pdo->query("SELECT 1 FROM stardust_slot_assignments WHERE status = 'tombstoned' LIMIT 1");

    return $stmt !== false && $stmt->fetchColumn() !== false;
}

function model_row_exists(PDO $pdo, int $modelId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM stardust_models WHERE id = ?');
    $stmt->execute([$modelId]);

    return $stmt->fetchColumn() !== false;
}
