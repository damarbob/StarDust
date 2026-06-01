<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Filter;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use StarDust\Filter\Json\JsonFilterDecoder;
use StarDust\Filter\Limits\FilterLimits;
use StarDust\Filter\QueryFilterValidationException;

/**
 * Phase 8 exit-criterion guard: the shipped normative JSON Schema
 * (`schemas/queryfilter.schema.json`) and the runtime
 * {@see JsonFilterDecoder} must reach the same accept/reject verdict.
 *
 * Without this lock the two specs can silently drift — the decoder is
 * the runtime boundary gate, but the schema is the artifact consumers
 * and CI validate against, so they have to agree.
 *
 * No database access (lives in the Smoke suite so it runs in CI). The
 * decoder is always constructed with default {@see FilterLimits} so its
 * fixed bounds line up with the schema's hardcoded ones (args 64,
 * in/nin 1024, prefix string 4096, between 2).
 *
 * The corpus is deliberately curated to the structural rules the two
 * implementations share; the documented strictness asymmetries (the
 * schema's `additionalProperties: false`, the decoder's `in`/`nin`
 * dedup and depth/node-count/payload-byte caps) are exercised in
 * {@see JsonFilterDecoderTest} and {@see testSchemaIsStricterOnUnknownKeys},
 * not in the shared agreement corpus.
 */
final class QueryFilterSchemaConformanceTest extends TestCase
{
    private const SCHEMA_PATH = __DIR__ . '/../../../schemas/queryfilter.schema.json';

    public function testSchemaFileShipsInPackage(): void
    {
        self::assertFileExists(
            self::SCHEMA_PATH,
            'queryfilter.schema.json must ship in the package, not only in SDDPG/',
        );
        $decoded = json_decode((string) file_get_contents(self::SCHEMA_PATH));
        self::assertSame(JSON_ERROR_NONE, json_last_error(), 'shipped schema must be valid JSON');
        self::assertIsObject($decoded);
        self::assertSame('https://stardust.internal/schemas/queryfilter/v1', $decoded->{'$id'} ?? null);
    }

    /**
     * @dataProvider corpus
     */
    public function testSchemaAndDecoderAgreeOnCorpus(string $payload, bool $shouldAccept): void
    {
        $schemaVerdict  = $this->schemaAccepts($payload);
        $decoderVerdict = $this->decoderAccepts($payload);

        self::assertSame(
            $shouldAccept,
            $schemaVerdict,
            "schema verdict disagrees with corpus label for payload: {$payload}",
        );
        self::assertSame(
            $shouldAccept,
            $decoderVerdict,
            "decoder verdict disagrees with corpus label for payload: {$payload}",
        );
        self::assertSame(
            $schemaVerdict,
            $decoderVerdict,
            "schema and decoder disagree (drift) for payload: {$payload}",
        );
    }

    public function testSchemaIsStricterOnUnknownKeys(): void
    {
        // Documented asymmetry: the schema's `additionalProperties:false`
        // rejects unknown node keys; the decoder ignores them. This is
        // the one place they intentionally diverge, so it is asserted
        // explicitly rather than left to a corpus row.
        $payload = (string) json_encode([
            'filter' => [
                'op'     => 'eq',
                'field'  => ['model' => 'inv', 'name' => 'status'],
                'value'  => 'paid',
                'unknown' => 'ignored-by-decoder',
            ],
        ]);

        self::assertFalse($this->schemaAccepts($payload), 'schema must reject unknown node keys');
        self::assertTrue($this->decoderAccepts($payload), 'decoder ignores unknown node keys (lenient)');
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function corpus(): array
    {
        $field = ['model' => 'inv', 'name' => 'status'];
        $numField = ['model' => 'inv', 'name' => 'amount'];
        $dateField = ['model' => 'inv', 'name' => 'due_date'];

        $accept = static fn (array $body): array => [(string) json_encode($body), true];
        $reject = static fn (mixed $body): array => [
            is_string($body) ? $body : (string) json_encode($body),
            false,
        ];

        return [
            // ---- accept: match-all envelopes ----
            // (literal '{}' — an empty PHP array would encode to '[]')
            'match-all empty'      => ['{}', true],
            'match-all versioned'  => $accept(['version' => '1']),

            // ---- accept: every closed-v1 leaf ----
            'eq'          => $accept(['filter' => ['op' => 'eq', 'field' => $field, 'value' => 'paid']]),
            'neq'         => $accept(['filter' => ['op' => 'neq', 'field' => $field, 'value' => 'void']]),
            'lt'          => $accept(['filter' => ['op' => 'lt', 'field' => $numField, 'value' => 10]]),
            'lte'         => $accept(['filter' => ['op' => 'lte', 'field' => $numField, 'value' => 10]]),
            'gt'          => $accept(['filter' => ['op' => 'gt', 'field' => $numField, 'value' => 10]]),
            'gte'         => $accept(['filter' => ['op' => 'gte', 'field' => $numField, 'value' => 10]]),
            'in'          => $accept(['filter' => ['op' => 'in', 'field' => $field, 'value' => ['a', 'b', 'c']]]),
            'nin'         => $accept(['filter' => ['op' => 'nin', 'field' => $field, 'value' => ['x', 'y']]]),
            'prefix'      => $accept(['filter' => ['op' => 'prefix', 'field' => $field, 'value' => 'pa']]),
            'between'     => $accept(['filter' => ['op' => 'between', 'field' => $numField, 'value' => [100, 500]]]),
            'is_null'     => $accept(['filter' => ['op' => 'is_null', 'field' => $dateField]]),
            'is_not_null' => $accept(['filter' => ['op' => 'is_not_null', 'field' => $dateField]]),

            // ---- accept: AND/OR/NOT composition ----
            'and-not-or'  => $accept(['filter' => [
                'op'   => 'and',
                'args' => [
                    ['op' => 'eq', 'field' => $field, 'value' => 'paid'],
                    ['op' => 'not', 'arg' => [
                        'op'   => 'or',
                        'args' => [
                            ['op' => 'lt', 'field' => $numField, 'value' => 10],
                            ['op' => 'is_null', 'field' => $dateField],
                        ],
                    ]],
                ],
            ]]),

            // ---- reject: envelope / structural ----
            'not-json'         => $reject('not json'),
            'array-root'       => $reject('[]'),
            'null-filter'      => $reject('{"filter": null}'),
            'bad-version'      => $reject(['version' => '2']),
            'unknown-operator' => $reject(['filter' => ['op' => 'matches_regex', 'field' => $field, 'value' => 'x']]),
            'missing-field'    => $reject(['filter' => ['op' => 'eq', 'value' => 'x']]),

            // ---- reject: leaf value shape / bounds ----
            'is_null-with-value' => $reject(['filter' => ['op' => 'is_null', 'field' => $dateField, 'value' => null]]),
            'empty-in'           => $reject(['filter' => ['op' => 'in', 'field' => $field, 'value' => []]]),
            'between-three'      => $reject(['filter' => ['op' => 'between', 'field' => $numField, 'value' => [1, 2, 3]]]),
            'empty-prefix'       => $reject(['filter' => ['op' => 'prefix', 'field' => $field, 'value' => '']]),
            'empty-and-args'     => $reject(['filter' => ['op' => 'and', 'args' => []]]),
            'over-limit-in'      => $reject(['filter' => ['op' => 'in', 'field' => $field, 'value' => range(0, 1024)]]),
        ];
    }

    private function schemaAccepts(string $payload): bool
    {
        $data = json_decode($payload);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        return (new Validator())->validate($data, self::schemaObject())->isValid();
    }

    private function decoderAccepts(string $payload): bool
    {
        try {
            (new JsonFilterDecoder(new FilterLimits()))->decode($payload);
            return true;
        } catch (QueryFilterValidationException) {
            return false;
        }
    }

    private static function schemaObject(): object
    {
        /** @var object $schema */
        $schema = json_decode((string) file_get_contents(self::SCHEMA_PATH));
        return $schema;
    }
}
