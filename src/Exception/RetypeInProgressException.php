<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when a second retype/promotion is initiated for a field that
 * already has a `backfill_checkpoints` row in `status='running'` keyed
 * `retype_field_{field_id}`. The first retype must complete before
 * another can start; concurrent lifecycles on the same field would race
 * on the same registry rows.
 *
 * ## Also raised model-wide, by compaction (ADR 0039)
 *
 * `compactModel()` raises it — for both the real run and `--dry-run` —
 * when **any** field of the target model has such a checkpoint running.
 * The reason is different from the per-field one: nothing would race,
 * but a field mid-relocation holds a `tombstoned` old slot and a
 * `backfilling` new one, and the planner's population counts neither, so
 * a plan built during that window reports a `pages_after` the ADR 0031
 * spread sample then contradicts. Compaction declines to report rather
 * than report wrongly.
 *
 * **Reused rather than split, deliberately.** The taxonomy splits when
 * two conditions *mean* different things — the reason
 * `NonFilterableFieldSlotException` and `FieldNotFilterableException`
 * are separate classes despite naming the same registry state. Here the
 * meaning is identical from the caller's side ("a retype lifecycle is in
 * flight on something you targeted; wait for the Reconciler and retry"),
 * and the remedy is the same. A `CompactionInProgressException` would
 * additionally be a lie: an ordinary `retypeField()` or
 * `promoteFieldToFilterable()` trips the compaction guard just as a
 * relocation does.
 */
final class RetypeInProgressException extends RuntimeException
{
}
