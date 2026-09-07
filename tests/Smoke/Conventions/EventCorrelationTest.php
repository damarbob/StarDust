<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Conventions;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every `source: registry` event must name its `correlation_id`
 * explicitly at the emit site.
 *
 * **The reason this needs a test at all is that the defect is
 * invisible.** `StdoutNdjsonLogger` synthesises a fresh v4 UUID for any
 * event whose context omits `correlation_id`, so an emit site that
 * leaves it out still produces a well-formed record that passes every
 * shape assertion in the suite — it just carries an id that correlates
 * to nothing. Nine sites drifted that way and were only caught by
 * reading two log lines side by side and noticing they would not join.
 *
 * So the rule is stricter than ADR 0020's wire contract: the id must be
 * *passed*, not merely *present*. That is what forces the author of a
 * new registry event to answer the question the ADR actually asks —
 * which operation is this event part of? — instead of inheriting a
 * plausible-looking answer for free.
 *
 * Scoped to the `registry` source deliberately. The daemon sources mint
 * a per-cycle or per-chunk id at the top of the tick and thread it
 * everywhere, so they have never had this failure mode; `registry` is
 * the source with no owning loop, which is exactly why it drifted.
 *
 * DB-free by design; a source scan, in the same spirit as
 * {@see \StarDust\Tests\Smoke\EventVocabularyTest} and
 * {@see BootstrapperTableAllowlistTest}.
 */
final class EventCorrelationTest extends TestCase
{
    private const SRC = __DIR__ . '/../../../src';

    /**
     * Every emit site carrying a `source` at the time of writing — the
     * real count, verified by enumeration, not a round number. A floor
     * rather than an allowlist: adding an event is fine, losing the
     * ability to see them is not.
     *
     * Distribution when this was set, as a sanity check for whoever
     * next has to reconcile a drift: reconciler 29, registry 15,
     * api 12, chronicler 11, watcher 6, bulk_api 6, liberator 5,
     * export_api 1.
     *
     * **This test cannot tell a threaded id from a freshly minted one**,
     * because both put the literal key at the emit site. That gap is
     * real and has already bitten: `CardinalitySampler` and
     * `SpreadSampler` passed this scan while minting their own ids
     * inside a retype promotion that already had one. Only
     * {@see \StarDust\Tests\Smoke\LifecycleCorrelationTest} can see
     * that, so a new registry event needs a behavioural test there too.
     */
    private const MINIMUM_EXPECTED_SITES = 85;

    public function testEveryEventNamesItsCorrelationId(): void
    {
        $blocks = $this->emitBlocks();

        self::assertGreaterThanOrEqual(
            self::MINIMUM_EXPECTED_SITES,
            count($blocks),
            'Found fewer registry emit sites than exist — the scan pattern has rotted.',
        );

        $offenders = [];
        foreach ($blocks as $location => $block) {
            if (! str_contains($block, "'correlation_id'")) {
                $offenders[] = $location;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "These `source: registry` events do not pass a correlation_id:\n  "
            . implode("\n  ", $offenders)
            . "\n\nThe logger will synthesise one, so the record still looks valid —"
            . ' which is precisely why this is a test and not a code review item.'
            . ' ADR 0020 requires the id be carried through every sub-event of the'
            . ' same operation, so pass the enclosing operation\'s id, or mint one'
            . ' with UuidV4::generate() if this call IS the operation boundary.',
        );
    }

    /**
     * Every logger-call array literal that sets `source` to `registry`,
     * keyed by `RelativePath.php:LINE` of the `source` line.
     *
     * A block runs from the `$this->logger->` that opens the call to the
     * `]);` that closes it. Both markers are unambiguous in this codebase
     * — every emit site is a single `logger->level('msg', [ ... ]);`
     * statement — so a structural parse would buy nothing here.
     *
     * @return array<string,string>
     */
    private function emitBlocks(): array
    {
        $blocks = [];

        foreach ($this->sourceFiles() as $path) {
            $lines = explode("\n", (string) file_get_contents($path));
            $relative = str_replace('\\', '/', substr($path, strlen(realpath(self::SRC)) + 1));

            foreach ($lines as $i => $line) {
                if (preg_match("/'source'\s*=>\s*'[a-z_]+'/", $line) !== 1) {
                    continue;
                }

                $start = $this->openingCallLine($lines, $i);
                $end   = $this->closingCallLine($lines, $i);

                $blocks[$relative . ':' . ($i + 1)] = implode(
                    "\n",
                    array_slice($lines, $start, $end - $start + 1),
                );
            }
        }

        return $blocks;
    }

    /**
     * @param list<string> $lines
     */
    private function openingCallLine(array $lines, int $from): int
    {
        for ($i = $from; $i >= 0; $i--) {
            if (str_contains($lines[$i], '->logger->')) {
                return $i;
            }
        }

        return $from;
    }

    /**
     * @param list<string> $lines
     */
    private function closingCallLine(array $lines, int $from): int
    {
        for ($i = $from, $n = count($lines); $i < $n; $i++) {
            if (preg_match('/^\s*\]\);\s*$/', $lines[$i]) === 1) {
                return $i;
            }
        }

        return $from;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::SRC, RecursiveDirectoryIterator::SKIP_DOTS)
            ) as $file
        ) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = (string) $file->getRealPath();
            }
        }

        sort($files);

        return $files;
    }
}
