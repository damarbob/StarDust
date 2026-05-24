<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use PHPUnit\Framework\TestCase;

/**
 * Closed-event-vocabulary guard for Phase 5.
 *
 * Greps `src/Watcher/` and `src/Reconciler/` for `'event' => '...'`
 * literals and asserts the union is a subset of the ADR 0020 allowlist
 * for those two sources. Adding a new event name without updating
 * ADR 0020 must fail this test.
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
    ];

    private const REGISTRY_EVENTS = [
        'page_provisioned',
        'slot_reserved',
        'cardinality_sampled',
        'low_cardinality_index',
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
