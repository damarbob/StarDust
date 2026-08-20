<?php

declare(strict_types=1);

namespace StarDust\Compaction;

use StarDust\Exception\CompactionCapacityException;
use StarDust\Watcher\SpreadSample;

/**
 * Chooses the minimal page set a model's live filterable slots should
 * occupy, and the moves that get them there (ADR 0033).
 *
 * **Pure.** It takes an already-loaded registry projection and returns a
 * {@see CompactionPlan}; it opens no connection, holds no lock, and
 * mutates nothing. {@see CompactionRepository} does the reading. That
 * split is what lets the whole policy be tested without a database, the
 * same posture as `ProvisioningPlanner` and `SpreadSample`.
 *
 * ## The population is ADR 0031's, deliberately
 *
 * Live filterable slots (`status IN ('assigned','ready')` +
 * `is_filterable = 1`) — the identical population `SpreadSampler`
 * measures. That is not a coincidence to be tidied away later: it is
 * what makes `excess_pages → 0` a real success criterion rather than
 * two subsystems agreeing by luck. The `theoretical_min_pages` formula
 * is reused from {@see SpreadSample} for the same reason.
 *
 * ## Candidates are pages the model already occupies
 *
 * A deliberate v1 restriction. Compaction *consolidates* — it never
 * migrates a model onto a page it has never touched, which keeps the
 * blast radius to pages the model already pays join cost for and
 * guarantees the search terminates (the current layout is always a
 * feasible fallback). The cost is that a large empty page elsewhere is
 * not considered, so a badly fragmented model on uniformly full pages
 * may report no admissible improvement even though the global pool has
 * room. Widening the candidate set is a future change; it needs a
 * policy for how aggressively compaction may claim shared capacity.
 *
 * ## Double-occupancy is handled by construction
 *
 * Free capacity is counted from rows that are `free` *now*. A slot being
 * vacated by this very plan is not free — it becomes `tombstoned` and
 * only returns after the Liberator sweeps it (ADR 0009). So the
 * arithmetic below never spends capacity the operation is about to
 * release, which is exactly the trap ADR 0033 calls out.
 */
final class CompactionPlanner
{
    /**
     * @param list<ModelSlot>                 $modelSlots   the model's live filterable slots
     * @param array<int, array<string, int>>  $freeCapacity pageId ⇒ family ⇒ free slot count
     *
     * @throws CompactionCapacityException when the model is fragmented but no
     *                                     smaller page set can absorb the moves
     */
    public static function plan(
        int $tenantId,
        int $modelId,
        array $modelSlots,
        array $freeCapacity,
    ): CompactionPlan {
        $pagesOccupied = self::distinctPages($modelSlots);
        $countsByFamily = self::countByFamily($modelSlots);
        $minPages = SpreadSample::theoreticalMinPages($countsByFamily);
        $pagesBefore = count($pagesOccupied);

        // Already at the floor — nothing to do, and this is a success,
        // not an error. A model whose family ceilings force three pages
        // reports `excess_pages = 0` and wants nothing from the operator.
        if ($pagesBefore <= $minPages || $modelSlots === []) {
            return new CompactionPlan(
                tenantId: $tenantId,
                modelId: $modelId,
                pagesBefore: $pagesBefore,
                theoreticalMinPages: $minPages,
                targetPageIds: $pagesOccupied,
                relocations: [],
                noopCount: count($modelSlots),
            );
        }

        $candidates = self::rankCandidates($pagesOccupied, $modelSlots, $freeCapacity);

        // Smallest admissible set that is a genuine improvement. Start at
        // the theoretical floor and widen until the moves fit; stop short
        // of `pagesBefore`, since a set that size relocates nothing.
        for ($size = max(1, $minPages); $size < $pagesBefore; $size++) {
            $targets = array_slice($candidates, 0, $size);
            $relocations = self::assign($modelSlots, $targets, $freeCapacity);

            if ($relocations !== null) {
                // `sort()` reindexes, so this stays a list.
                sort($targets);

                return new CompactionPlan(
                    tenantId: $tenantId,
                    modelId: $modelId,
                    pagesBefore: $pagesBefore,
                    theoreticalMinPages: $minPages,
                    targetPageIds: $targets,
                    relocations: $relocations,
                    noopCount: count($modelSlots) - count($relocations),
                );
            }
        }

        throw new CompactionCapacityException(sprintf(
            'Cannot compact model %d (tenant %d): it occupies %d pages against a floor of %d,'
            . ' but no smaller page set has enough free slots of the required families to absorb'
            . ' the moves. A relocated field holds its old slot until the Liberator sweeps it'
            . ' (ADR 0009), so let the tombstone backlog drain, let the Watcher provision, and'
            . ' re-run. Nothing was mutated.',
            $modelId,
            $tenantId,
            $pagesBefore,
            $minPages,
        ));
    }

    /**
     * Rank the model's pages by how much of the model they could host —
     * slots already there, plus free slots they could take. Ties break on
     * page id so planning is deterministic and re-runs are stable.
     *
     * @param  list<int>                     $pagesOccupied
     * @param  list<ModelSlot>               $modelSlots
     * @param  array<int, array<string, int>> $freeCapacity
     * @return list<int>
     */
    private static function rankCandidates(
        array $pagesOccupied,
        array $modelSlots,
        array $freeCapacity,
    ): array {
        $hosted = [];
        foreach ($modelSlots as $slot) {
            $hosted[$slot->pageId] = ($hosted[$slot->pageId] ?? 0) + 1;
        }

        $ranked = $pagesOccupied;
        usort($ranked, static function (int $a, int $b) use ($hosted, $freeCapacity): int {
            $powerA = ($hosted[$a] ?? 0) + array_sum($freeCapacity[$a] ?? []);
            $powerB = ($hosted[$b] ?? 0) + array_sum($freeCapacity[$b] ?? []);

            return $powerB <=> $powerA ?: $a <=> $b;
        });

        return $ranked;
    }

    /**
     * Try to house every field on the target set.
     *
     * Fields already on a target page stay put and cost nothing. Every
     * other field needs a `free` slot of its family on some target page;
     * capacity is decremented as it is spent, so two fields never claim
     * the same slot.
     *
     * @param  list<ModelSlot>                $modelSlots
     * @param  list<int>                      $targets
     * @param  array<int, array<string, int>> $freeCapacity
     * @return list<FieldRelocation>|null     null when the set cannot absorb the moves
     */
    private static function assign(array $modelSlots, array $targets, array $freeCapacity): ?array
    {
        $remaining = [];
        foreach ($targets as $pageId) {
            $remaining[$pageId] = $freeCapacity[$pageId] ?? [];
        }

        $relocations = [];
        foreach ($modelSlots as $slot) {
            if (in_array($slot->pageId, $targets, true)) {
                continue;
            }

            $placed = false;
            // Ascending page id keeps placement deterministic and packs
            // the oldest target page first, matching the density
            // preference the reserver has always had.
            $ordered = $targets;
            sort($ordered);

            foreach ($ordered as $pageId) {
                if (($remaining[$pageId][$slot->slotType] ?? 0) < 1) {
                    continue;
                }
                $remaining[$pageId][$slot->slotType]--;
                $relocations[] = new FieldRelocation(
                    fieldId: $slot->fieldId,
                    fieldName: $slot->fieldName,
                    slotType: $slot->slotType,
                    fromPageId: $slot->pageId,
                    toPageId: $pageId,
                );
                $placed = true;
                break;
            }

            if (! $placed) {
                return null;
            }
        }

        return $relocations;
    }

    /**
     * @param  list<ModelSlot> $modelSlots
     * @return list<int>       ascending, distinct
     */
    private static function distinctPages(array $modelSlots): array
    {
        $pages = [];
        foreach ($modelSlots as $slot) {
            $pages[$slot->pageId] = true;
        }
        $ids = array_keys($pages);
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<ModelSlot>      $modelSlots
     * @return array<string, int>   family ⇒ count
     */
    private static function countByFamily(array $modelSlots): array
    {
        $counts = [];
        foreach ($modelSlots as $slot) {
            $counts[$slot->slotType] = ($counts[$slot->slotType] ?? 0) + 1;
        }

        return $counts;
    }
}
