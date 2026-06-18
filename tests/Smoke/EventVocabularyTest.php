<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use PHPUnit\Framework\TestCase;

/**
 * Closed-event-vocabulary guard for Phase 5 through Phase 8.
 *
 * Greps `src/Watcher/`, `src/Reconciler/`, `src/Liberator/`,
 * `src/Retype/`, `src/Chronicler/`, `src/Export/`, `src/Search/`,
 * and `src/Filter/` for `'event' => '...'` literals and asserts the
 * union is a subset of the ADR 0020 allowlist for each source.
 * Adding a new event name without updating ADR 0020 must fail this
 * test.
 */
final class EventVocabularyTest extends TestCase
{
    private const WATCHER_EVENTS = [
        'poll_started',
        'poll_complete',
        'provision_started',
        'provision_complete',
        'provision_failed',
        'lock_contention',
    ];

    private const RECONCILER_EVENTS = [
        'chunk_claimed',
        'chunk_complete',
        'chunk_partial',
        'dlq_inserted',
        'cache_miss',
        'capacity_wait',
        'coercion_null',
        'lease_lost',
    ];

    private const LIBERATOR_EVENTS = [
        'sweep_started',
        'sweep_chunk',
        'sweep_complete',
        'deadlock_retry',
        'sweep_gap_flagged',
    ];

    private const REGISTRY_EVENTS = [
        'page_provisioned',
        'slot_reserved',
        'cardinality_sampled',
        'low_cardinality_index',
        'retype_started',
        'promote_to_ready',
    ];

    private const CHRONICLER_EVENTS = [
        'job_claimed',
        'chunk_written',
        'deadlock_retry',
        'chunk_skipped',
        'row_skipped',
        'lease_lost',
        'low_disk',
        'artifact_oversized',
        'job_complete',
        'job_failed',
        'gc_swept',
    ];

    private const EXPORT_API_EVENTS = [
        'export_accepted',
    ];

    private const SEARCH_API_EVENTS = [
        'search_request',
        'capability_unsupported',
        'pre_flight_rejected',
        'cache_miss',
    ];

    public function testWatcherSourceUsesOnlyAllowedEventNames(): void
    {
        $allowed = array_merge(self::WATCHER_EVENTS, self::REGISTRY_EVENTS);
        $found = $this->scanDir(__DIR__ . '/../../src/Watcher');
        foreach ($found as $event) {
            self::assertContains(
                $event,
                $allowed,
                "Event '{$event}' is not in the Watcher/Registry allowlist (ADR 0020)."
            );
        }
        self::assertNotEmpty($found);
    }

    public function testReconcilerSourceUsesOnlyAllowedEventNames(): void
    {
        $found = $this->scanDir(__DIR__ . '/../../src/Reconciler');
        foreach ($found as $event) {
            self::assertContains(
                $event,
                self::RECONCILER_EVENTS,
                "Event '{$event}' is not in the Reconciler allowlist (ADR 0020)."
            );
        }
        self::assertNotEmpty($found);
    }

    public function testLiberatorSourceUsesOnlyAllowedEventNames(): void
    {
        $found = $this->scanDir(__DIR__ . '/../../src/Liberator');
        foreach ($found as $event) {
            self::assertContains(
                $event,
                self::LIBERATOR_EVENTS,
                "Event '{$event}' is not in the Liberator allowlist (ADR 0020)."
            );
        }
        self::assertNotEmpty($found);
    }

    public function testRetypeSourceUsesOnlyAllowedEventNames(): void
    {
        // Retype emits a mix of reconciler-source events (from the
        // work source's chunk lifecycle) and registry-source events
        // (retype_started, promote_to_ready, slot_reserved).
        $allowed = array_merge(self::RECONCILER_EVENTS, self::REGISTRY_EVENTS);
        $found = $this->scanDir(__DIR__ . '/../../src/Retype');
        foreach ($found as $event) {
            self::assertContains(
                $event,
                $allowed,
                "Event '{$event}' is not in the Retype/Reconciler/Registry allowlist (ADR 0020)."
            );
        }
        self::assertNotEmpty($found);
    }

    public function testChroniclerSourceUsesOnlyAllowedEventNames(): void
    {
        $found = $this->scanDir(__DIR__ . '/../../src/Chronicler');
        foreach ($found as $event) {
            self::assertContains(
                $event,
                self::CHRONICLER_EVENTS,
                "Event '{$event}' is not in the Chronicler allowlist (ADR 0020)."
            );
        }
        self::assertNotEmpty($found);
    }

    public function testExportApiSourceUsesOnlyAllowedEventNames(): void
    {
        $found = $this->scanDir(__DIR__ . '/../../src/Export');
        foreach ($found as $event) {
            self::assertContains(
                $event,
                self::EXPORT_API_EVENTS,
                "Event '{$event}' is not in the Export API allowlist (ADR 0020)."
            );
        }
        self::assertNotEmpty($found);
    }

    public function testSearchSourceUsesOnlyAllowedEventNames(): void
    {
        // Phase 8 emits `search_request` and `capability_unsupported`
        // from the search service / capability checker, plus reuses
        // `pre_flight_rejected` (shared with Phase 4 read path) for
        // generic rejections. The decoder under src/Filter/ throws
        // typed exceptions and emits no events itself — the scan still
        // covers that directory for forward-compatibility.
        $found = array_unique(array_merge(
            $this->scanDir(__DIR__ . '/../../src/Search'),
            $this->scanDir(__DIR__ . '/../../src/Filter'),
        ));
        foreach ($found as $event) {
            self::assertContains(
                $event,
                self::SEARCH_API_EVENTS,
                "Event '{$event}' is not in the Search API allowlist (ADR 0020)."
            );
        }
        self::assertNotEmpty($found, 'Search namespace should emit at least one structured-log event');
    }

    /** @return list<string> */
    private function scanDir(string $dir): array
    {
        $events = [];
        if (!is_dir($dir)) {
            return $events;
        }
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($iter as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            if (preg_match_all("/'event'\s*=>\s*'([a-z_]+)'/", $content, $matches) > 0) {
                foreach ($matches[1] as $name) {
                    $events[] = $name;
                }
            }
        }
        return array_values(array_unique($events));
    }
}
