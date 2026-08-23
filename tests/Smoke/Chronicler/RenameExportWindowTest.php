<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Reconciler\TickOutcome;
use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * Exports running while an ADR 0036 field rename is still draining.
 *
 * `entry_data.fields` is keyed by field name, so mid-rename some rows
 * carry the old key and some the new. CSV export projects the payload
 * against a fixed header list derived from `stardust_fields`, which by
 * then says the *new* name — so without the alias every un-migrated row
 * emits a blank cell under a correct column. That failure is silent:
 * `?? null` becomes `''`, no exception is raised, no `row_skipped` fires
 * and `skip_count` stays zero, and unlike a read the artifact is never
 * retried. `HeaderResolver::resolveAliases()` exists to close it.
 *
 * JSON is deliberately NOT aliased — see the asymmetry test below.
 */
final class RenameExportWindowTest extends Phase7TestCase
{
    private const ROW_COUNT  = 6;
    private const RENAME_CHUNK = 2;

    /**
     * Seeds a JSON-only field, renames it, and drains exactly one chunk
     * so the model is genuinely half-migrated.
     *
     * Rows go through `seedEntry()` (the real `EntryWriter`) rather than
     * `seedEntryDataBatch()`, which bypasses the write path — the point
     * is that these rows hold the pre-rename key the way production rows
     * would.
     *
     * @return array{0: int, 1: int} [modelId, fieldId]
     */
    private function halfMigratedModel(string $modelName): array
    {
        $modelId = $this->createModel(1, $modelName);
        $fieldId = $this->createField($modelId, 'string', false, 'title');

        for ($i = 1; $i <= self::ROW_COUNT; $i++) {
            $this->seedEntry(1, $modelId, ['title' => "v{$i}"]);
        }

        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');
        self::assertSame(
            TickOutcome::WORK_DONE,
            $this->runRenameTick(null, self::RENAME_CHUNK),
        );

        return [$modelId, $fieldId];
    }

    /** Entry ids whose stored payload still carries `$key`. */
    private function idsStillOnKey(int $modelId, string $key): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM entry_data'
            . " WHERE model_id = ? AND JSON_CONTAINS_PATH(fields, 'one', ?)"
            . ' ORDER BY id'
        );
        $stmt->execute([$modelId, '$."' . $key . '"']);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testCsvPopulatesCellsForRowsStillOnThePreRenameKey(): void
    {
        [$modelId] = $this->halfMigratedModel('rename_csv_window');

        // Without this the test could pass vacuously: if the single tick
        // happened to drain everything, there would be no stale key left
        // for the alias to resolve and the assertions below would hold
        // even with the alias removed.
        $stale = $this->idsStillOnKey($modelId, 'title');
        self::assertNotEmpty($stale, 'Fixture must leave rows on the pre-rename key.');
        self::assertNotEmpty(
            $this->idsStillOnKey($modelId, 'headline'),
            'Fixture must also have migrated some rows, or the window is not half-open.',
        );

        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');
        $this->makeChronicler()->tick();

        $job = $this->fetchExportJob($jobId);
        self::assertSame('completed', $job['status']);
        self::assertSame(0, (int) $job['skip_count'], 'A rename window must not skip rows.');

        $artifact = $this->readArtifactCsv((string) $job['artifact_path']);
        self::assertCount(self::ROW_COUNT, $artifact);

        foreach ($artifact as $i => $row) {
            self::assertArrayHasKey(
                'headline',
                $row,
                'Header must carry the current field name.',
            );
            self::assertArrayNotHasKey('title', $row, 'Header must not carry the old name.');
            self::assertNotSame(
                '',
                $row['headline'],
                "Row {$i} exported a blank cell — the rename alias is not reaching CsvArtifactStream.",
            );
        }

        // The values themselves must be intact, not merely non-empty.
        $values = array_map(static fn (array $r): string => $r['headline'], $artifact);
        sort($values);
        self::assertSame(['v1', 'v2', 'v3', 'v4', 'v5', 'v6'], $values);
    }

    /**
     * The documented asymmetry, pinned so nobody "fixes" it.
     *
     * `JsonArtifactStream` emits the payload verbatim per ADR 0013 and
     * takes no header list, so a consumer sees whichever key the row
     * actually holds and can cope. CSV cannot, because it projects
     * against a header that would otherwise lie.
     */
    public function testJsonArtifactIsDeliberatelyNotAliased(): void
    {
        [$modelId] = $this->halfMigratedModel('rename_json_window');

        $staleIds = $this->idsStillOnKey($modelId, 'title');
        self::assertNotEmpty($staleIds);

        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'json');
        $this->makeChronicler()->tick();

        $job = $this->fetchExportJob($jobId);
        self::assertSame('completed', $job['status']);

        $rows = $this->readArtifactJson((string) $job['artifact_path']);
        self::assertCount(self::ROW_COUNT, $rows);

        $keysSeen = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $keysSeen[$key] = true;
            }
        }

        self::assertArrayHasKey(
            'title',
            $keysSeen,
            'JSON must emit the payload verbatim, old key included.',
        );
        self::assertArrayHasKey('headline', $keysSeen);
    }

    /**
     * Once the backfill clears `previous_name`, `resolveAliases()`
     * returns `[]` and the export must be byte-identical to one from a
     * model that was never renamed.
     */
    public function testExportIsUnchangedOnceTheRenameCompletes(): void
    {
        [$modelId, $fieldId] = $this->halfMigratedModel('rename_settled');

        while ($this->runRenameTick(null, self::RENAME_CHUNK) === TickOutcome::WORK_DONE) {
        }
        self::assertNull($this->fetchFieldRow($fieldId)['previous_name']);
        self::assertSame([], $this->idsStillOnKey($modelId, 'title'));

        $renamedJob = $this->seedExportJob(1, $modelId, 'pending', 'csv');
        $this->makeChronicler()->tick();
        $renamedCsv = (string) file_get_contents(
            (string) $this->fetchExportJob($renamedJob)['artifact_path']
        );

        // A never-renamed model with the same shape and values.
        $controlModel = $this->createModel(1, 'rename_control');
        $this->createField($controlModel, 'string', false, 'headline');
        for ($i = 1; $i <= self::ROW_COUNT; $i++) {
            $this->seedEntry(1, $controlModel, ['headline' => "v{$i}"]);
        }
        $controlJob = $this->seedExportJob(1, $controlModel, 'pending', 'csv');
        $this->makeChronicler()->tick();
        $controlCsv = (string) file_get_contents(
            (string) $this->fetchExportJob($controlJob)['artifact_path']
        );

        self::assertSame(
            $controlCsv,
            $renamedCsv,
            'A settled rename must leave no trace in the export.',
        );
    }
}
