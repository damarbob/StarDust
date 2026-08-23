<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Rename;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StarDust\Config\Config;
use StarDust\Exception\ModelNameConflictException;
use StarDust\Exception\ModelNotFoundException;
use StarDust\Read\EntryQuery;
use StarDust\Rename\ModelRenamer;
use StarDust\Schema\SchemaReader;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * Model rename — one UPDATE, no backfill, no window.
 *
 * A model's name is a label rather than an identity, so the interesting
 * assertions here are mostly about what does *not* happen: no schema
 * version bump, no effect on entries or slots, and no cache to
 * invalidate.
 */
final class ModelRenameTest extends Phase6bTestCase
{
    private function renamer(?LoggerInterface $logger = null): ModelRenamer
    {
        return new ModelRenamer(
            pdo: $this->pdo,
            logger: $logger ?? new NullLogger(),
        );
    }

    private function modelName(int $modelId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM stardust_models WHERE id = ?');
        $stmt->execute([$modelId]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string) $name;
    }

    public function testRenameCommitsTheNewName(): void
    {
        $modelId = $this->createModel(1, 'invoice');

        $this->renamer()->rename(1, $modelId, 'bill');

        self::assertSame('bill', $this->modelName($modelId));
    }

    /**
     * The load-bearing negative assertion.
     *
     * `SchemaBuilder::createModel()` bumps the version when it inserts a
     * model, so the omission here looks like an oversight unless it is
     * pinned. Nothing a cached snapshot holds changes on a model rename,
     * and the version row is a singleton — bumping would invalidate
     * every model's snapshot in every process for nothing.
     */
    public function testRenameDoesNotBumpSchemaVersion(): void
    {
        $modelId = $this->createModel(1, 'invoice');
        $before = $this->fetchSchemaVersion();

        $this->renamer()->rename(1, $modelId, 'bill');

        self::assertSame(
            $before,
            $this->fetchSchemaVersion(),
            'A model rename changes nothing any snapshot caches; bumping the singleton '
            . 'version row would invalidate every model in every process for no benefit.',
        );
    }

    public function testEmitsModelRenamedOnceAfterCommit(): void
    {
        $modelId = $this->createModel(1, 'invoice');

        $logger = $this->makeRecordingLogger();
        $this->renamer($logger)->rename(1, $modelId, 'bill');

        $records = $this->recordsWithEvent($logger->records(), 'model_renamed');
        self::assertCount(1, $records);
        self::assertSame('registry', $records[0]['context']['source']);
        self::assertSame(1, $records[0]['context']['tenant_id']);
        self::assertSame($modelId, $records[0]['context']['model_id']);
        self::assertSame('invoice', $records[0]['context']['old_name']);
        self::assertSame('bill', $records[0]['context']['new_name']);
    }

    public function testRenamingToTheSameNameIsASilentNoOp(): void
    {
        $modelId = $this->createModel(1, 'invoice');
        $before = $this->fetchSchemaVersion();

        $logger = $this->makeRecordingLogger();
        $this->renamer($logger)->rename(1, $modelId, 'invoice');

        self::assertSame('invoice', $this->modelName($modelId));
        self::assertSame($before, $this->fetchSchemaVersion());
        self::assertSame([], $this->recordsWithEvent($logger->records(), 'model_renamed'));
    }

    public function testForeignTenantIsRejected(): void
    {
        $modelId = $this->createModel(1, 'invoice');

        $this->expectException(ModelNotFoundException::class);
        $this->renamer()->rename(2, $modelId, 'bill');
    }

    public function testUnknownModelIsRejectedIdenticallyToAForeignOne(): void
    {
        // Same exception type for "never existed" and "not yours", so a
        // caller cannot probe another tenant's model ids.
        $this->expectException(ModelNotFoundException::class);
        $this->renamer()->rename(1, 999_999, 'bill');
    }

    public function testEmptyOrWhitespaceNameIsRejected(): void
    {
        $modelId = $this->createModel(1, 'invoice');

        $this->expectException(InvalidArgumentException::class);
        $this->renamer()->rename(1, $modelId, '   ');
    }

    public function testOverlongNameIsRejected(): void
    {
        $modelId = $this->createModel(1, 'invoice');

        $this->expectException(InvalidArgumentException::class);
        $this->renamer()->rename(1, $modelId, str_repeat('x', 129));
    }

    public function testCollisionWithAnotherModelInTheSameTenantIsRejected(): void
    {
        $invoice = $this->createModel(1, 'invoice');
        $this->createModel(1, 'bill');

        $this->expectException(ModelNameConflictException::class);
        $this->renamer()->rename(1, $invoice, 'bill');
    }

    /**
     * The unique key is `(tenant_id, name)`, so two tenants may each
     * have a model called `bill`. This is the test that catches a
     * collision check that forgot its tenant predicate.
     */
    public function testTheSameNameInADifferentTenantIsAllowed(): void
    {
        $this->createModel(2, 'bill');
        $invoice = $this->createModel(1, 'invoice');

        $this->renamer()->rename(1, $invoice, 'bill');

        self::assertSame('bill', $this->modelName($invoice));
    }

    /**
     * Entries, fields and slots all reference `model_id`, so a rename
     * must be invisible to every one of them.
     */
    public function testNothingElseMoves(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1, 'invoice');
        $fieldId = $this->createField($modelId, 'string', true, 'title');
        $this->reserveSlotFor($fieldId);
        $this->seedEntry(1, $modelId, ['title' => 'a']);
        $this->seedEntry(1, $modelId, ['title' => 'b']);

        $slotBefore = $this->fetchLiveSlotForField($fieldId);
        $engine = new StarDust(new Config(pdo: $this->pdo));
        $before = $engine->read(new EntryQuery(tenantId: 1, modelId: $modelId, pageSize: 50));

        $this->renamer()->rename(1, $modelId, 'bill');

        $after = $engine->read(new EntryQuery(tenantId: 1, modelId: $modelId, pageSize: 50));

        self::assertCount(2, $after->rows);
        self::assertSame(
            array_map(static fn ($e): int => $e->id, $before->rows),
            array_map(static fn ($e): int => $e->id, $after->rows),
            'Reads are keyed by model_id and must be unaffected.',
        );
        self::assertSame('a', $after->rows[0]->fields['title']);
        self::assertEquals(
            $slotBefore,
            $this->fetchLiveSlotForField($fieldId),
            'A model rename must not touch any slot assignment.',
        );
        self::assertSame(
            'title',
            $this->fetchFieldRow($fieldId)['name'],
            'Field names are untouched by a model rename.',
        );
    }

    /**
     * `SchemaReader` is an uncached registry read, which is also why no
     * version bump is needed for introspection to be correct.
     */
    public function testIntrospectionReflectsTheNewNameImmediately(): void
    {
        $modelId = $this->createModel(1, 'invoice');
        $reader = new SchemaReader($this->pdo);

        $this->renamer()->rename(1, $modelId, 'bill');

        $description = $reader->describeModel(1, $modelId);
        self::assertNotNull($description);
        self::assertSame('bill', $description->name);

        $names = array_map(
            static fn ($m): string => $m->name,
            $reader->listModels(1),
        );
        self::assertContains('bill', $names);
        self::assertNotContains('invoice', $names);
    }

    /**
     * The documented footgun, pinned so it is known behaviour rather
     * than a surprise.
     *
     * `SchemaBuilder::findModelId()` looks up by `(tenant_id, name)`, so
     * a seed script still naming the old model creates a second one
     * instead of finding it. Inherent to get-or-create; not fixable
     * without changing those semantics.
     */
    public function testSeedingWithTheOldNameCreatesASecondModel(): void
    {
        $engine = new StarDust(new Config(pdo: $this->pdo, logger: new NullLogger()));
        $original = $engine->schemaBuilder()->defineModel(1, 'invoice');

        $engine->renameModel(1, $original, 'bill');

        $reDefined = $engine->schemaBuilder()->defineModel(1, 'invoice');

        self::assertNotSame(
            $original,
            $reDefined,
            'Get-or-create by name cannot find a renamed model, so it creates a new one.',
        );

        $count = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM stardust_models WHERE tenant_id = 1')
            ->fetchColumn();
        self::assertSame(2, $count, 'The tenant now has both the renamed model and a fresh one.');
    }

    public function testFacadeRejectsInvalidTenant(): void
    {
        $modelId = $this->createModel(1, 'invoice');
        $engine = new StarDust(new Config(pdo: $this->pdo, logger: new NullLogger()));

        $this->expectException(\StarDust\Exception\InvalidTenantIdException::class);
        $engine->renameModel(0, $modelId, 'bill');
    }
}
