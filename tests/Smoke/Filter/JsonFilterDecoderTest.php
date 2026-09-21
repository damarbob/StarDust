<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Filter;

use PHPUnit\Framework\TestCase;
use StarDust\Filter\Ast\AndNode;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Ast\NotNode;
use StarDust\Filter\Ast\OrNode;
use StarDust\Filter\Json\JsonFilterDecoder;
use StarDust\Filter\Limits\FilterLimits;
use StarDust\Filter\QueryFilterValidationException;
use StarDust\Filter\ValidationErrorCode;

/**
 * Pure unit tests for the Phase 8 wire-format decoder. Every closed
 * error code in {@see ValidationErrorCode} that the decoder is
 * responsible for has a happy-fail case; every closed v1 leaf
 * operator has a happy-pass case. No database access.
 */
final class JsonFilterDecoderTest extends TestCase
{
    private function decoder(?FilterLimits $limits = null): JsonFilterDecoder
    {
        return new JsonFilterDecoder($limits ?? new FilterLimits());
    }

    private function assertRejects(string $expectedCode, string $payload, ?FilterLimits $limits = null): QueryFilterValidationException
    {
        try {
            $this->decoder($limits)->decode($payload);
            self::fail("expected {$expectedCode} for payload: {$payload}");
        } catch (QueryFilterValidationException $e) {
            self::assertSame(
                $expectedCode,
                $e->errorCode,
                "wrong code; got '{$e->errorCode}' (pointer={$e->jsonPointer}, message={$e->getMessage()})"
            );
            return $e;
        }
    }

    public function testMatchAllWhenFilterKeyAbsent(): void
    {
        self::assertNull($this->decoder()->decode('{}'));
        self::assertNull($this->decoder()->decode('{"version":"1"}'));
    }

    public function testEnvelopeMalformedWhenRootIsNotAnObject(): void
    {
        // Rejected by the leading-`{` guard, before json_decode() runs.
        $this->assertRejects(ValidationErrorCode::ENVELOPE_MALFORMED, 'not json');
    }

    public function testEnvelopeMalformedOnInvalidJson(): void
    {
        // Passes the leading-`{` guard, so this is the case that actually
        // reaches json_decode() and its JsonException catch.
        $e = $this->assertRejects(ValidationErrorCode::ENVELOPE_MALFORMED, '{"filter":');
        self::assertSame('', $e->jsonPointer);
    }

    public function testEnvelopeMalformedOnArrayRoot(): void
    {
        $this->assertRejects(ValidationErrorCode::ENVELOPE_MALFORMED, '[]');
    }

    public function testNodeMalformedOnNullFilter(): void
    {
        $this->assertRejects(ValidationErrorCode::NODE_MALFORMED, '{"filter": null}');
    }

    public function testVersionUnsupported(): void
    {
        $this->assertRejects(ValidationErrorCode::VERSION_UNSUPPORTED, '{"version": "2"}');
    }

    public function testOperatorUnknown(): void
    {
        $payload = json_encode([
            'filter' => [
                'op' => 'matches_regex',
                'field' => ['model' => 'm', 'name' => 'n'],
                'value' => 'x',
            ],
        ]);
        $this->assertRejects(ValidationErrorCode::OPERATOR_UNKNOWN, (string) $payload);
    }

    public function testValueUnexpectedOnIsNullWithValue(): void
    {
        $payload = json_encode([
            'filter' => [
                'op' => 'is_null',
                'field' => ['model' => 'm', 'name' => 'n'],
                'value' => null,
            ],
        ]);
        $this->assertRejects(ValidationErrorCode::VALUE_UNEXPECTED, (string) $payload);
    }

    public function testValueCountMismatchOnEmptyIn(): void
    {
        $payload = json_encode([
            'filter' => ['op' => 'in', 'field' => ['model' => 'm', 'name' => 'n'], 'value' => []],
        ]);
        $this->assertRejects(ValidationErrorCode::VALUE_COUNT_MISMATCH, (string) $payload);
    }

    public function testValueCountMismatchOnBetweenWithThreeElements(): void
    {
        $payload = json_encode([
            'filter' => ['op' => 'between', 'field' => ['model' => 'm', 'name' => 'n'], 'value' => [1, 2, 3]],
        ]);
        $this->assertRejects(ValidationErrorCode::VALUE_COUNT_MISMATCH, (string) $payload);
    }

    public function testValueCountMismatchOnEmptyAndArgs(): void
    {
        $payload = json_encode(['filter' => ['op' => 'and', 'args' => []]]);
        $this->assertRejects(ValidationErrorCode::VALUE_COUNT_MISMATCH, (string) $payload);
    }

    public function testValueOutOfBoundsOnEmptyPrefix(): void
    {
        $payload = json_encode([
            'filter' => ['op' => 'prefix', 'field' => ['model' => 'm', 'name' => 'n'], 'value' => ''],
        ]);
        $this->assertRejects(ValidationErrorCode::VALUE_OUT_OF_BOUNDS, (string) $payload);
    }

    public function testValueOutOfBoundsOnInArrayOverLimit(): void
    {
        $values = range(0, 1024); // 1025 elements > limit of 1024
        $payload = json_encode([
            'filter' => ['op' => 'in', 'field' => ['model' => 'm', 'name' => 'n'], 'value' => $values],
        ]);
        $this->assertRejects(ValidationErrorCode::VALUE_OUT_OF_BOUNDS, (string) $payload);
    }

    public function testNestingTooDeep(): void
    {
        $limits = new FilterLimits(maxDepth: 3);
        $payload = [
            'op' => 'not',
            'arg' => [
                'op' => 'not',
                'arg' => [
                    'op' => 'not',
                    'arg' => [
                        'op'    => 'eq',
                        'field' => ['model' => 'm', 'name' => 'n'],
                        'value' => 'x',
                    ],
                ],
            ],
        ];
        $envelope = json_encode(['filter' => $payload]);
        $this->assertRejects(ValidationErrorCode::NESTING_TOO_DEEP, (string) $envelope, $limits);
    }

    public function testNodeCountExceeded(): void
    {
        $limits = new FilterLimits(maxNodes: 2);
        $payload = [
            'op' => 'and',
            'args' => [
                ['op' => 'eq', 'field' => ['model' => 'm', 'name' => 'n'], 'value' => 'a'],
                ['op' => 'eq', 'field' => ['model' => 'm', 'name' => 'n'], 'value' => 'b'],
            ],
        ];
        $envelope = json_encode(['filter' => $payload]);
        $this->assertRejects(ValidationErrorCode::NODE_COUNT_EXCEEDED, (string) $envelope, $limits);
    }

    public function testNodeMalformedOnMissingField(): void
    {
        $payload = json_encode(['filter' => ['op' => 'eq', 'value' => 'x']]);
        $this->assertRejects(ValidationErrorCode::NODE_MALFORMED, (string) $payload);
    }

    public function testValueOutOfBoundsOnPayloadSize(): void
    {
        $limits = new FilterLimits(maxPayloadBytes: 32);
        $this->assertRejects(ValidationErrorCode::VALUE_OUT_OF_BOUNDS, str_repeat(' ', 64), $limits);
    }

    public function testJsonPointerOnNestedFailure(): void
    {
        $payload = json_encode([
            'filter' => [
                'op' => 'and',
                'args' => [
                    ['op' => 'eq', 'field' => ['model' => 'm', 'name' => 'n'], 'value' => 'a'],
                    ['op' => 'in', 'field' => ['model' => 'm', 'name' => 'n'], 'value' => []],
                ],
            ],
        ]);
        $e = $this->assertRejects(ValidationErrorCode::VALUE_COUNT_MISMATCH, (string) $payload);
        self::assertSame('/filter/args/1/value', $e->jsonPointer);
    }

    /**
     * One row per reject branch in the decoder, each pinned to the code
     * and the JSON Pointer it must report.
     *
     * @dataProvider rejectCases
     */
    public function testRejectsWithCodeAndPointer(string $expectedCode, string $payload, string $expectedPointer): void
    {
        $e = $this->assertRejects($expectedCode, $payload);
        self::assertSame($expectedPointer, $e->jsonPointer);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function rejectCases(): array
    {
        $field = ['model' => 'm', 'name' => 'n'];
        $leaf = ['op' => 'eq', 'field' => $field, 'value' => 'x'];
        $tooLong = str_repeat('a', FilterLimits::DEFAULT_MAX_STRING_LENGTH + 1);

        $filter = static fn (mixed $node): string => (string) json_encode(['filter' => $node]);
        $malformed = ValidationErrorCode::NODE_MALFORMED;
        $bounds = ValidationErrorCode::VALUE_OUT_OF_BOUNDS;

        return [
            // ---- envelope ----
            'filter is a string'       => [ValidationErrorCode::ENVELOPE_MALFORMED, '{"filter":"x"}', '/filter'],
            'filter is a JSON array'   => [ValidationErrorCode::ENVELOPE_MALFORMED, '{"filter":[1]}', '/filter'],
            'filter is an empty object' => [$malformed, '{"filter":{}}', '/filter'],

            // ---- node ----
            'op is not a string'       => [$malformed, $filter(['op' => 1]), '/filter'],
            'not without arg'          => [$malformed, $filter(['op' => 'not']), '/filter'],
            'not arg is a scalar'      => [$malformed, $filter(['op' => 'not', 'arg' => 'x']), '/filter/arg'],
            'and without args'         => [$malformed, $filter(['op' => 'and']), '/filter'],
            'and args is an object'    => [$malformed, $filter(['op' => 'and', 'args' => ['a' => $leaf]]), '/filter/args'],
            'or child is a scalar'     => [$malformed, $filter(['op' => 'or', 'args' => ['x']]), '/filter/args/0'],
            'and args over the limit'  => [$bounds, $filter(['op' => 'and', 'args' => array_fill(0, FilterLimits::DEFAULT_MAX_ARGS + 1, $leaf)]), '/filter/args'],

            // ---- field ref ----
            'field is a string'        => [$malformed, $filter(['op' => 'eq', 'field' => 'm.n', 'value' => 'x']), '/filter/field'],
            'field.model missing'      => [$malformed, $filter(['op' => 'eq', 'field' => ['name' => 'n'], 'value' => 'x']), '/filter/field/model'],
            'field.model empty'        => [$malformed, $filter(['op' => 'eq', 'field' => ['model' => '', 'name' => 'n'], 'value' => 'x']), '/filter/field/model'],
            'field.name empty'         => [$malformed, $filter(['op' => 'eq', 'field' => ['model' => 'm', 'name' => ''], 'value' => 'x']), '/filter/field/name'],

            // ---- single-value leaf ----
            'eq without value'         => [$malformed, $filter(['op' => 'eq', 'field' => $field]), '/filter'],
            'eq value is an array'     => [$malformed, $filter(['op' => 'eq', 'field' => $field, 'value' => [1]]), '/filter/value'],
            'eq value is null'         => [$malformed, $filter(['op' => 'eq', 'field' => $field, 'value' => null]), '/filter/value'],
            'prefix value is a number' => [$malformed, $filter(['op' => 'prefix', 'field' => $field, 'value' => 7]), '/filter/value'],

            // ---- set / range leaf ----
            'in value is a scalar'     => [$malformed, $filter(['op' => 'in', 'field' => $field, 'value' => 'x']), '/filter/value'],
            'in value is an object'    => [$malformed, $filter(['op' => 'in', 'field' => $field, 'value' => ['a' => 1]]), '/filter/value'],
            'in element is null'       => [$malformed, $filter(['op' => 'in', 'field' => $field, 'value' => ['a', null]]), '/filter/value/1'],
            'between value is a scalar' => [$malformed, $filter(['op' => 'between', 'field' => $field, 'value' => 1]), '/filter/value'],
            'between element is array' => [$malformed, $filter(['op' => 'between', 'field' => $field, 'value' => [[1], 2]]), '/filter/value/0'],

            // ---- string length bound, on every operator that carries a string ----
            'eq string over the limit'      => [$bounds, $filter(['op' => 'eq', 'field' => $field, 'value' => $tooLong]), '/filter/value'],
            'prefix string over the limit'  => [$bounds, $filter(['op' => 'prefix', 'field' => $field, 'value' => $tooLong]), '/filter/value'],
            'in element over the limit'     => [$bounds, $filter(['op' => 'in', 'field' => $field, 'value' => ['a', $tooLong]]), '/filter/value/1'],
            'between element over the limit' => [$bounds, $filter(['op' => 'between', 'field' => $field, 'value' => ['a', $tooLong]]), '/filter/value/1'],
        ];
    }

    public function testStringLimitCountsCharactersNotBytes(): void
    {
        // 4096 two-byte characters is 8192 bytes but exactly at the limit.
        $atLimit = str_repeat('é', FilterLimits::DEFAULT_MAX_STRING_LENGTH);
        $node = $this->decoder()->decode((string) json_encode([
            'filter' => ['op' => 'eq', 'field' => ['model' => 'm', 'name' => 'n'], 'value' => $atLimit],
        ]));
        self::assertInstanceOf(LeafNode::class, $node);
        self::assertSame($atLimit, $node->value?->value);
    }

    public function testHappyPathEq(): void
    {
        $node = $this->decoder()->decode((string) json_encode([
            'filter' => ['op' => 'eq', 'field' => ['model' => 'inv', 'name' => 'status'], 'value' => 'paid'],
        ]));
        self::assertInstanceOf(LeafNode::class, $node);
        self::assertSame('eq', $node->operator);
        self::assertSame('inv', $node->field->modelName);
        self::assertSame('status', $node->field->fieldName);
        self::assertSame('paid', $node->value?->value);
    }

    public function testHappyPathBetween(): void
    {
        $node = $this->decoder()->decode((string) json_encode([
            'filter' => ['op' => 'between', 'field' => ['model' => 'inv', 'name' => 'amount'], 'value' => [100, 500]],
        ]));
        self::assertInstanceOf(LeafNode::class, $node);
        self::assertSame([100, 500], $node->value?->value);
    }

    public function testHappyPathIsNull(): void
    {
        $node = $this->decoder()->decode((string) json_encode([
            'filter' => ['op' => 'is_null', 'field' => ['model' => 'inv', 'name' => 'due_date']],
        ]));
        self::assertInstanceOf(LeafNode::class, $node);
        self::assertNull($node->value);
    }

    public function testHappyPathAndOrNot(): void
    {
        $node = $this->decoder()->decode((string) json_encode([
            'filter' => [
                'op' => 'and',
                'args' => [
                    ['op' => 'eq', 'field' => ['model' => 'inv', 'name' => 'status'], 'value' => 'paid'],
                    [
                        'op' => 'not',
                        'arg' => [
                            'op' => 'or',
                            'args' => [
                                ['op' => 'lt', 'field' => ['model' => 'inv', 'name' => 'amount'], 'value' => 10],
                                ['op' => 'is_null', 'field' => ['model' => 'inv', 'name' => 'due_date']],
                            ],
                        ],
                    ],
                ],
            ],
        ]));
        self::assertInstanceOf(AndNode::class, $node);
        self::assertCount(2, $node->args);
        self::assertInstanceOf(NotNode::class, $node->args[1]);
        $or = $node->args[1]->arg;
        self::assertInstanceOf(OrNode::class, $or);
        self::assertCount(2, $or->args);
    }

    public function testInDeduplicates(): void
    {
        $node = $this->decoder()->decode((string) json_encode([
            'filter' => ['op' => 'in', 'field' => ['model' => 'm', 'name' => 'n'], 'value' => ['a', 'b', 'a', 'b', 'c']],
        ]));
        self::assertInstanceOf(LeafNode::class, $node);
        self::assertSame(['a', 'b', 'c'], $node->value?->value);
    }
}
