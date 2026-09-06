<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Search;

use Psr\Log\NullLogger;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Ast\TypedValue;
use StarDust\Filter\QueryFilterValidationException;
use StarDust\Filter\ValidationErrorCode;
use StarDust\Read\EntryQuery;
use StarDust\Read\SchemaVersionCache;
use StarDust\Search\PreFlight\CapabilityChecker;
use StarDust\Search\PreFlight\FieldRefResolver;
use StarDust\Search\PreFlight\PreFlightPipeline;
use StarDust\Search\PreFlight\SortValidator;
use StarDust\Search\PreFlight\ValueTypeValidator;
use StarDust\Search\SearchRequest;
use StarDust\Tests\Smoke\Phase8TestCase;

/**
 * A `datetime` filter bound carries an explicit UTC offset — the
 * wire format rejects the naive form outright — and pre-flight now
 * converts it to the instant it names before the driver sees it.
 *
 * Before this landed the offset was validated and then discarded:
 * MySQL truncates an RFC 3339 literal at the zone designator, so
 * `…T10:00:00+07:00` matched the row holding `10:00`, not the one
 * holding `03:00`, and did so silently — the value passed pre-flight,
 * the query still planned as a range scan, and the only complaint was
 * a warning 1292 nothing reads.
 *
 * The fixture reuses {@see \StarDust\Tests\Smoke\ReadPathTestCase::setupSortableModel()},
 * whose `due_at` field is a filterable, slot-backed `datetime` on
 * `i_dt_01`, and seeds through the real `EntryWriter` — so stored
 * values pass through `PayloadSplitter` exactly as production does.
 * That matters most for {@see testAnInstantMatchesItselfAcrossTheWriteAndFilterPaths}:
 * the write path already normalised, and the filter path did not, so
 * the two disagreed about the same instant.
 */
final class DatetimeBoundNormalisationTest extends Phase8TestCase
{
    /**
     * The defect, stated as the round trip it broke: write an instant
     * with a non-zero offset, filter for that same literal, get it back.
     */
    public function testAnInstantMatchesItselfAcrossTheWriteAndFilterPaths(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $field = $names['datetime'];
        $this->seedEntry(1, $modelId, [$field => '2026-01-01T10:00:00+07:00']);

        $rows = $this->searchRows($modelId, LeafNode::local($field, 'eq', '2026-01-01T10:00:00+07:00'), $field);

        self::assertCount(1, $rows, 'the instant written did not match a filter for the same instant');
    }

    /**
     * The offset is applied rather than parsed off: `10:00+07:00` is
     * `03:00Z`, so it must find the 03:00 row and not the 10:00 one.
     * This is the assertion that fails in the wrong direction — before
     * the fix it returned the 10:00 row, which looks like a result.
     */
    public function testANonZeroOffsetSelectsTheInstantAndNotTheWallClock(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $field = $names['datetime'];
        $this->seedEntry(1, $modelId, [$field => '2026-01-01T03:00:00Z']);
        $this->seedEntry(1, $modelId, [$field => '2026-01-01T10:00:00Z']);

        $rows = $this->searchRows($modelId, LeafNode::local($field, 'eq', '2026-01-01T10:00:00+07:00'), $field);

        self::assertSame(['2026-01-01 03:00:00'], $rows);
    }

    /**
     * The `Z` form was correct by accident — dropping a zero offset
     * changes nothing — and must stay correct now that it is correct
     * on purpose.
     */
    public function testZuluFormStillMatches(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $field = $names['datetime'];
        $this->seedEntry(1, $modelId, [$field => '2026-01-01T03:00:00Z']);
        $this->seedEntry(1, $modelId, [$field => '2026-01-01T10:00:00Z']);

        $rows = $this->searchRows($modelId, LeafNode::local($field, 'eq', '2026-01-01T10:00:00Z'), $field);

        self::assertSame(['2026-01-01 10:00:00'], $rows);
    }

    /**
     * `between` and `in` travel the compiler's list path rather than
     * its scalar path, so they need their own coverage — normalising
     * only the scalar branch would leave both silently wrong.
     */
    public function testRangeAndSetOperatorsNormaliseEveryBound(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $field = $names['datetime'];
        $this->seedEntry(1, $modelId, [$field => '2026-01-01T02:00:00Z']);
        $this->seedEntry(1, $modelId, [$field => '2026-01-01T03:00:00Z']);
        $this->seedEntry(1, $modelId, [$field => '2026-01-01T09:00:00Z']);

        // 09:00+07:00 .. 11:00+07:00 is 02:00Z .. 04:00Z.
        $between = $this->searchRows($modelId, LeafNode::local($field, 'between', [
            '2026-01-01T09:00:00+07:00',
            '2026-01-01T11:00:00+07:00',
        ]), $field);
        self::assertSame(['2026-01-01 02:00:00', '2026-01-01 03:00:00'], $between);

        $in = $this->searchRows($modelId, LeafNode::local($field, 'in', [
            '2026-01-01T10:00:00+07:00',
            '2026-01-01T16:00:00+07:00',
        ]), $field);
        self::assertSame(['2026-01-01 03:00:00', '2026-01-01 09:00:00'], $in);

        // 10:00+07:00 is 03:00Z; strictly earlier leaves only 02:00Z.
        $lt = $this->searchRows($modelId, LeafNode::local($field, 'lt', '2026-01-01T10:00:00+07:00'), $field);
        self::assertSame(['2026-01-01 02:00:00'], $lt);
    }

    /**
     * Fractional seconds are carried through rather than floored.
     *
     * A slot column is `DATETIME`, second precision, so flooring looks
     * harmless — but MySQL compares a fractional constant against it
     * exactly (verified on 8.0.13: `'…10:00:00' < '…10:00:00.5'` is
     * true), and a floored `lt` bound would wrongly exclude the row
     * sitting on the second boundary.
     */
    public function testFractionalSecondsAreNotTruncatedAwayFromABound(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $field = $names['datetime'];
        $this->seedEntry(1, $modelId, [$field => '2026-01-01T10:00:00Z']);

        $rows = $this->searchRows($modelId, LeafNode::local($field, 'lt', '2026-01-01T10:00:00.5Z'), $field);

        self::assertSame(['2026-01-01 10:00:00'], $rows);
    }

    /**
     * Normalisation must not quietly widen what the validator accepts:
     * a naive datetime is still `value_type_mismatch`, per the wire
     * format's criterion 20.
     */
    public function testNaiveDatetimeIsStillRejected(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $field = $names['datetime'];

        try {
            $this->makeSearchService()->execute(new SearchRequest(
                tenantId: 1,
                modelId:  $modelId,
                filter:   LeafNode::local($field, 'eq', '2026-01-01T10:00:00'),
                pageSize: 10,
            ));
            self::fail('a naive datetime bound should not have been accepted');
        } catch (QueryFilterValidationException $e) {
            self::assertSame(ValidationErrorCode::VALUE_TYPE_MISMATCH, $e->errorCode);
        }
    }

    /**
     * What a driver actually receives.
     *
     * The AST is driver-neutral per ADR 0022, so pre-flight hands on
     * canonical UTC RFC 3339 — not a MySQL `DATETIME` literal, which
     * would be meaningless to a driver backed by anything else.
     * Rendering that literal is the MySQL compiler's job, and nothing
     * but this test pins the division.
     */
    public function testPreFlightHandsTheDriverCanonicalUtcRfc3339(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $field = $names['datetime'];
        $logger = new NullLogger();

        $pipeline = new PreFlightPipeline(
            fieldRefResolver:   new FieldRefResolver($logger),
            capabilityChecker:  new CapabilityChecker($logger),
            valueTypeValidator: new ValueTypeValidator($logger),
            sortValidator:      new SortValidator($logger),
        );
        $snapshot = (new SchemaVersionCache($this->pdo, $logger))
            ->snapshotForModel($modelId, 1, 'test-correlation');

        $resolved = $pipeline->validate(
            LeafNode::local($field, 'eq', '2026-01-01T10:00:00+07:00'),
            $snapshot,
            $this->makeMysqlDriver($logger),
            1,
            'test-correlation',
        );

        self::assertInstanceOf(LeafNode::class, $resolved);
        self::assertInstanceOf(TypedValue::class, $resolved->value);
        self::assertSame('2026-01-01T03:00:00Z', $resolved->value->value);
    }

    /**
     * The emitted SQL is clean, not merely correct.
     *
     * MySQL accepts an RFC 3339 literal against a `DATETIME` column and
     * raises `Warning 1292 Incorrect datetime value` every time — the
     * same warning that let the discarded offset go unnoticed, since
     * nothing on the read path reads it. Binding the column's own
     * literal removes it, and this is the only assertion that
     * distinguishes the compiler's half of the work from pre-flight's:
     * neuter `toMysqlLiteral()` and every other test here stays green,
     * because a canonical `…Z` bound is one MySQL truncates *correctly*.
     *
     * It asserts on the binding rather than on `SHOW WARNINGS`, which
     * cannot be read back reliably here: the suite's PDO uses native
     * prepares, `SHOW WARNINGS` is unsupported in that protocol, and
     * the attempt replaces the very list it meant to read (observed as
     * a lone 1295). The absence of the 1292 was verified directly
     * against 8.0.13 instead; this pins the binding that causes it.
     */
    public function testTheCompilerBindsAMysqlDatetimeLiteral(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $field = $names['datetime'];
        $this->seedEntry(1, $modelId, [$field => '2026-01-01T03:00:00Z']);
        $logger = new NullLogger();

        $filter = LeafNode::local($field, 'eq', '2026-01-01T10:00:00+07:00');
        $pipeline = new PreFlightPipeline(
            fieldRefResolver:   new FieldRefResolver($logger),
            capabilityChecker:  new CapabilityChecker($logger),
            valueTypeValidator: new ValueTypeValidator($logger),
            sortValidator:      new SortValidator($logger),
        );
        $snapshot = (new SchemaVersionCache($this->pdo, $logger))
            ->snapshotForModel($modelId, 1, 'test-correlation');
        $resolved = $pipeline->validate($filter, $snapshot, $this->makeMysqlDriver($logger), 1, 'test-correlation');

        $query = new EntryQuery(tenantId: 1, modelId: $modelId, filter: $resolved, pageSize: 10);
        $fragment = $this->makeCompiler()->compile($resolved, $query, $snapshot);

        self::assertContains('2026-01-01 03:00:00', $fragment->bindings);
        self::assertNotContains('2026-01-01T03:00:00Z', $fragment->bindings);

        // And the literal it swapped in still selects the right row.
        $stmt = $this->pdo->prepare($fragment->sql);
        $stmt->execute($fragment->bindings);
        self::assertCount(1, $stmt->fetchAll(\PDO::FETCH_ASSOC), 'the fragment should still select the 03:00Z row');
    }

    /**
     * Runs one filter and returns the matched rows' datetime values in
     * the form the column holds them, sorted, so an assertion reads as
     * the instants it selected rather than as entry ids.
     *
     * @return list<string>
     */
    private function searchRows(int $modelId, LeafNode $filter, string $field): array
    {
        $result = $this->makeSearchService()->execute(new SearchRequest(
            tenantId: 1,
            modelId:  $modelId,
            filter:   $filter,
            pageSize: 10,
        ));

        $values = [];
        foreach ($result->rows as $entry) {
            $values[] = (new \DateTimeImmutable((string) $entry->fields[$field]))
                ->format('Y-m-d H:i:s');
        }
        sort($values);
        return $values;
    }
}
