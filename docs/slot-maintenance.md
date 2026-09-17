# Slot maintenance

`spread:report`, `cardinality:report`, and `compact:model` — the [CLI commands](cli.md) — are convenience wrappers over public PHP entry points; call those directly for a settings dashboard or an automated maintenance job:

```php
use StarDust\Exception\RetypeInProgressException;

// Read-only, registry-only, safe against production at any time.
// One SpreadSample per (tenant, model) that has a live filterable slot.
foreach ($engine->spreadSampler()->report(tenantId: 42) as $sample) {
    // $sample->pagesOccupied, $sample->theoreticalMinPages, $sample->excessPages()
    if ($sample->excessPages() > 0) {
        echo "model {$sample->modelId}: {$sample->excessPages()} avoidable page(s)\n";
    }
}

// Read-only, but NOT registry-only — this one scans COUNT(*) /
// COUNT(DISTINCT col) over every matching extension page, so prefer
// off-peak on a large dataset. $modelId narrows which slots are
// sampled; each one's counts still cover the tenant's whole partition
// on that page, since the index being measured is (tenant_id, slot_column).
foreach ($engine->cardinalitySampler()->report(tenantId: 42) as $sample) {
    // $sample->rowCount, $sample->distinctValues, $sample->selectivity
    if ($sample->selectivity < 0.01) {
        echo "slot {$sample->slotColumn} on page {$sample->pageId}: low selectivity\n";
    }
}

// Plan without mutating anything.
$plan = $engine->compactModel(tenantId: 42, modelId: $modelId, dryRun: true);
// $plan->relocationCount(), $plan->pagesAfter(), $plan->excessPagesRemoved(), $plan->isNoop()

// Long-running and operator-initiated: moves one field at a time and
// blocks until a running Reconciler drains each relocation. Never call
// this from a request path.
try {
    $plan = $engine->compactModel(tenantId: 42, modelId: $modelId);
} catch (RetypeInProgressException $e) {
    // Refused — dry run included — while any field of the model is
    // still being retyped, promoted, demoted or relocated. Wait for
    // the Reconciler and re-run; spread:report stays available.
}
```
