<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Read;

use PDOException;
use StarDust\Clock\SystemClock;
use StarDust\Config\Config;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * The point read gets the schema version in the same statement as the entry.
 *
 * That version has to do what a separate check did: a snapshot cached at the
 * same version is reused, a newer version loads a fresh snapshot and reports
 * one `cache_miss`, and the fresh snapshot is what rewrites a renamed field's
 * key. `ReadStatementCountTest` pins that the check is no longer a statement of
 * its own, and these tests pin that nothing it protected was lost with it.
 */
final class PointReadVersionTest extends Phase6bTestCase
{
    /** @var resource */
    private $stream;

    private function engine(): StarDust
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->stream = $stream;

        return new StarDust(new Config(pdo: $this->pdo, logger: new StdoutNdjsonLogger(new SystemClock(), $stream)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cacheMisses(): array
    {
        rewind($this->stream);
        $misses = [];
        foreach (explode("\n", trim((string) stream_get_contents($this->stream))) as $line) {
            $decoded = $line === '' ? null : json_decode($line, true);
            if (is_array($decoded) && ($decoded['event'] ?? null) === 'cache_miss') {
                $misses[] = $decoded;
            }
        }

        return $misses;
    }

    private function liveVersion(): int
    {
        return (int) $this->pdo->query('SELECT version FROM stardust_schema_version WHERE id = 1')->fetchColumn();
    }

    public function testEachRefreshIsReportedOnceAndAWarmReadIsNot(): void
    {
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', false, 'title');
        $entryId = $this->seedEntry(1, $modelId, ['title' => 'a']);
        $engine = $this->engine();

        self::assertNotNull($engine->get(1, $entryId));
        $first = $this->cacheMisses();
        self::assertCount(1, $first, 'The first point read loads the snapshot.');
        self::assertNull($first[0]['cached_version']);
        self::assertSame($this->liveVersion(), $first[0]['live_version']);

        self::assertNotNull($engine->get(1, $entryId));
        self::assertCount(1, $this->cacheMisses(), 'A second point read at the same version reuses it.');

        $before = $this->liveVersion();
        $this->pdo->exec('UPDATE stardust_schema_version SET version = version + 1 WHERE id = 1');

        self::assertNotNull($engine->get(1, $entryId));
        $misses = $this->cacheMisses();
        self::assertCount(2, $misses, 'A version bump costs exactly one more refresh.');
        self::assertSame($before, $misses[1]['cached_version']);
        self::assertSame($before + 1, $misses[1]['live_version']);

        self::assertNotNull($engine->get(1, $entryId));
        self::assertCount(2, $this->cacheMisses());
    }

    /**
     * The snapshot is warm and holds no rename. The rename commits and bumps the
     * version, no row has been migrated yet, and the very next point read must
     * already return the new key. That only happens if the version that came
     * with the entry was compared with the cached snapshot's.
     */
    public function testARenameThatCommitsAfterTheSnapshotWasCachedIsSeenByTheNextPointRead(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        $entryId = $this->seedEntry(1, $modelId, ['title' => 'a']);
        $engine = $this->engine();

        $warm = $engine->get(1, $entryId);
        self::assertNotNull($warm);
        self::assertSame(['title' => 'a'], $warm->fields);

        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');
        self::assertSame(['title' => 'a'], $this->rawPayload($entryId), 'No row has been migrated yet.');

        $after = $engine->get(1, $entryId);
        self::assertNotNull($after);
        self::assertSame(['headline' => 'a'], $after->fields);
        self::assertCount(2, $this->cacheMisses());
    }

    /**
     * A missing singleton row yields a NULL version in the combined statement,
     * and the point read then fails the way every other read does.
     */
    public function testAMissingSchemaVersionRowRaisesTheSameErrorAsAnyOtherRead(): void
    {
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', false, 'title');
        $entryId = $this->seedEntry(1, $modelId, ['title' => 'a']);
        $engine = $this->engine();

        $this->pdo->exec('DELETE FROM stardust_schema_version');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('stardust_schema_version row is missing');
        $engine->get(1, $entryId);
    }
}
