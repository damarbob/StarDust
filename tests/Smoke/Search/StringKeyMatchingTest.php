<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Search;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Config\Config;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Read\EntryQuery;
use StarDust\Read\SlotResolver;
use StarDust\Search\Mysql\SqlFilterCompiler;
use StarDust\StarDust;
use StarDust\Tests\Smoke\ReadPathTestCase;
use StarDust\Tests\Smoke\Support\TestPdo;
use Throwable;

/**
 * The key lookup against real data, for every operator, over values chosen to
 * make a slightly wrong lookup go wrong.
 *
 * A lookup through a string slot's generated key (ADR 0057) that is wrong
 * returns the wrong rows and raises nothing, so this is the test that decides
 * whether the rewrite is right. For each probe it runs the filter through the
 * engine's own read path, so the pre-flight, the rewrite and the recheck all
 * take part, and compares the entries it returns with an oracle: the same
 * predicate run straight against the slot column, which sits in no index on a
 * key page and is therefore compared the way the collation says.
 *
 * The stored values pair up what a naive key would confuse. They cover case
 * and accent variants (`paid`, `Paid`, `païd`), `ß` against `ss`, characters
 * the collation ignores (a soft hyphen, a zero-width space), NFC against NFD,
 * full-width forms, CJK, emoji, an empty string, and values that straddle the
 * 16 letter head of the key and the 766 and 767 character marks of the old
 * prefix index, up to the 4,096 character filter bound. Every operand is a
 * stored value, so each lookup has neighbours that are equal under the
 * collation and neighbours that are not.
 *
 * It runs on native and emulated prepares and on three connection collations,
 * because the search value arrives in the connection's collation and the cast
 * to the page's is what makes it match. A mismatch is reported as a list, so
 * a broken layout shows how wrong it is rather than only that it is.
 *
 * Each load-bearing part was checked by breaking it and watching this go red:
 * the cast to the page collation, the zero padding of the lower bound, the 0xFF
 * fill of the upper bound, and the key predicate itself.
 */
final class StringKeyMatchingTest extends ReadPathTestCase
{
    private int $modelId;

    private int $pageId;

    /** @var array<int, string> entry id => label */
    private array $labels = [];

    protected function setUp(): void
    {
        parent::setUp();

        [$this->modelId, , $this->pageId] = $this->setupFilterableStringField();
        foreach (self::storedValues() as $label => $value) {
            $this->labels[$this->seedEntry(1, $this->modelId, ['name' => $value])] = $label;
        }
        $this->labels[$this->seedEntry(1, $this->modelId, ['name' => null])] = 'NULL';
    }

    /** @return array<string, string> label => value */
    private static function storedValues(): array
    {
        $x = static fn (int $n): string => str_repeat('x', $n);
        $emoji = static fn (int $n): string => str_repeat("\u{1F600}", $n);

        return [
            'paid' => 'paid', 'Paid' => 'Paid', 'PAID' => 'PAID', 'païd' => 'païd', 'PAÏD' => 'PAÏD',
            'pad' => 'pad', 'pa' => 'pa', 'pai' => 'pai', 'paidx' => 'paidx',
            'paid+space' => 'paid ', 'space+paid' => ' paid', 'empty' => '',
            'ß' => 'ß', 'ss' => 'ss', 'SS' => 'SS', 'straße' => 'straße', 'strasse' => 'strasse',
            'emoji1' => "\u{1F600}", 'emoji2' => "\u{1F601}", 'ǅ' => 'ǅ', 'dž' => 'dž',
            'café NFC' => "caf\u{00E9}", 'café NFD' => "cafe\u{0301}",
            'soft hyphen' => "pa\u{00AD}id", 'zero-width space' => "paid\u{200B}",
            'fullwidth paid' => "\u{FF50}\u{FF41}\u{FF49}\u{FF44}",
            'zhongwen' => "\u{4E2D}\u{6587}", 'zhong' => "\u{4E2D}", 'zhong+zwsp+wen' => "\u{4E2D}\u{200B}\u{6587}",
            'hangul NFC' => "\u{D55C}\u{AE00}", 'hangul NFD' => "\u{1112}\u{1161}\u{11AB}\u{1100}\u{1173}\u{11AF}",
            // Around the head: 16 letters fill the 32 bytes of weight that the key keeps.
            'x15' => $x(15), 'x16' => $x(16), 'x17' => $x(17),
            'x16+a' => $x(16) . 'a', 'x16+A' => $x(16) . 'A', 'x16+b' => $x(16) . 'b',
            'x15+ß+y' => $x(15) . 'ßy', 'x15+ssy' => $x(15) . 'ssy',
            // Around the old prefix index.
            'x765+ß+y' => $x(765) . 'ßy', 'x765+ssy' => $x(765) . 'ssy',
            'x765+s' => $x(765) . 's', 'x765+ss' => $x(765) . 'ss', 'x765+ß' => $x(765) . 'ß',
            'x766' => $x(766), 'x766+a' => $x(766) . 'a', 'x766+A' => $x(766) . 'A', 'x766+b' => $x(766) . 'b',
            'x766+zwsp' => $x(766) . "\u{200B}", 'x767' => $x(767),
            'x765+é NFC+y' => $x(765) . "\u{00E9}y", 'x765+é NFD+y' => $x(765) . "e\u{0301}y",
            'x765+shy+x' => $x(765) . "\u{00AD}x",
            'emoji766' => $emoji(766), 'emoji766+a' => $emoji(766) . 'a',
            'emoji765+ß+y' => $emoji(765) . 'ßy', 'emoji765+ssy' => $emoji(765) . 'ssy',
            // The filter bound.
            'x4095+y' => $x(4095) . 'y', 'x4096' => $x(4096),
        ];
    }

    /** @return iterable<string, array{bool, ?string}> */
    public static function connections(): iterable
    {
        yield 'native prepares, server collation' => [false, null];
        yield 'emulated prepares, server collation' => [true, null];
        yield 'native prepares, utf8mb4_bin' => [false, 'utf8mb4_bin'];
        yield 'emulated prepares, utf8mb4_general_ci' => [true, 'utf8mb4_general_ci'];
    }

    private function engineOn(bool $emulated, ?string $collation): StarDust
    {
        $pdo = new PDO(
            getenv('STARDUST_TEST_DSN') ?: '',
            getenv('STARDUST_TEST_USER') ?: '',
            getenv('STARDUST_TEST_PASS') ?: '',
            [PDO::ATTR_EMULATE_PREPARES => $emulated] + TestPdo::options(),
        );
        $pdo->exec('SET NAMES utf8mb4' . ($collation === null ? '' : " COLLATE {$collation}"));

        return new StarDust(new Config(pdo: $pdo, logger: new NullLogger(), clock: new SystemClock()));
    }

    /**
     * Every probe as [description, operator, operand].
     *
     * @return iterable<int, array{string, string, mixed}>
     */
    private function probes(): iterable
    {
        $stored = self::storedValues();
        $extra = ['PAİD' => 'PAİD', 'PAID+space' => 'PAID ', 'zzz' => 'zzz', 'Zzz' => 'ZZZ'];

        foreach ($stored + $extra as $label => $value) {
            foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte'] as $op) {
                yield ["{$label}", $op, $value];
            }
        }

        foreach ($stored as $label => $value) {
            $length = mb_strlen($value);
            foreach ([1, 2, 3, 8, 15, 16, 17] as $n) {
                if ($n <= $length) {
                    yield ["{$label}[0..{$n}]", 'prefix', mb_substr($value, 0, $n)];
                }
            }
            if ($value !== '') {
                yield ["{$label} whole", 'prefix', $value];
            }
        }
        foreach (['50%' => '50%', 'a_b' => 'a_b', 'back\\slash' => 'back\\slash'] as $label => $value) {
            yield [$label, 'prefix', $value];
        }

        $names = array_keys($stored);
        for ($i = 0; $i + 2 < count($names); $i += 3) {
            $a = $stored[$names[$i]];
            $b = $stored[$names[$i + 1]];
            $c = $stored[$names[$i + 2]];
            yield ["{$names[$i]}, {$names[$i + 1]}, {$names[$i + 2]}", 'in', [$a, $b, $c]];
            yield ["{$names[$i]}, {$names[$i + 1]}, {$names[$i + 2]}", 'nin', [$a, $b, $c]];
            yield ["{$names[$i]} to {$names[$i + 2]}", 'between', [$a, $c]];
            yield ["{$names[$i + 2]} to {$names[$i]}", 'between', [$c, $a]];
        }

        yield ['', 'is_null', null];
        yield ['', 'is_not_null', null];
    }

    /**
     * What the slot column says, compared the way the collation says.
     *
     * @return list<int>
     */
    private function oracle(string $op, mixed $value): array
    {
        $in = static fn (array $v): string => implode(',', array_fill(0, count($v), '?'));

        [$where, $bindings] = match ($op) {
            'eq'          => ['p.i_str_01 = ?', [$value]],
            'neq'         => ['p.i_str_01 <> ?', [$value]],
            'lt'          => ['p.i_str_01 < ?', [$value]],
            'lte'         => ['p.i_str_01 <= ?', [$value]],
            'gt'          => ['p.i_str_01 > ?', [$value]],
            'gte'         => ['p.i_str_01 >= ?', [$value]],
            'in'          => ['p.i_str_01 IN (' . $in($value) . ')', $value],
            'nin'         => ['p.i_str_01 NOT IN (' . $in($value) . ')', $value],
            'between'     => ['p.i_str_01 BETWEEN ? AND ?', $value],
            'prefix'      => ["p.i_str_01 LIKE ? ESCAPE '\\\\'", [strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%']],
            'is_null'     => ['p.i_str_01 IS NULL', []],
            'is_not_null' => ['p.i_str_01 IS NOT NULL', []],
        };

        $stmt = $this->pdo->prepare(
            "SELECT p.entry_id FROM entry_slots_page_{$this->pageId} p"
            . ' JOIN entry_data d ON d.id = p.entry_id AND d.tenant_id = p.tenant_id'
            . " WHERE p.tenant_id = 1 AND d.deleted_at IS NULL AND {$where} ORDER BY p.entry_id"
        );
        $stmt->execute($bindings);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    private function found(StarDust $engine, string $op, mixed $value): array
    {
        $page = $engine->read(new EntryQuery(
            tenantId: 1,
            modelId:  $this->modelId,
            filter:   LeafNode::local('name', $op, $value),
            pageSize: EntryQuery::MAX_PAGE_SIZE,
        ));

        return array_map(static fn ($entry): int => $entry->id, $page->rows);
    }

    /** @param list<int> $ids */
    private function named(array $ids): string
    {
        return '[' . implode(', ', array_map(fn (int $id): string => $this->labels[$id] ?? "#{$id}", $ids)) . ']';
    }

    #[DataProvider('connections')]
    public function testEveryOperatorFindsExactlyWhatTheCollationSays(bool $emulated, ?string $collation): void
    {
        $engine = $this->engineOn($emulated, $collation);

        $probes = 0;
        $mismatches = [];
        foreach ($this->probes() as [$description, $op, $value]) {
            $probes++;
            $expected = $this->oracle($op, $value);
            try {
                $actual = $this->found($engine, $op, $value);
            } catch (Throwable $e) {
                $mismatches[] = "{$op} {$description}: threw " . $e::class . ': ' . $e->getMessage();
                continue;
            }
            if ($actual !== $expected) {
                $mismatches[] = "{$op} {$description}: got {$this->named($actual)} want {$this->named($expected)}";
            }
        }

        self::assertGreaterThan(750, $probes, 'The probe set must stay broad.');
        self::assertSame(
            [],
            array_slice($mismatches, 0, 12),
            count($mismatches) . " of {$probes} probes returned the wrong entries. The first are shown.",
        );
    }

    /**
     * Without this the test above could pass without the rewrite taking part:
     * a snapshot that recorded no key would compile to the old SQL, which is
     * correct, and the differential would have nothing to prove.
     */
    public function testTheKeyRewriteIsInPlayForThisPage(): void
    {
        $snapshot = SlotResolver::load($this->pdo, $this->modelId, 1);
        self::assertNotNull($snapshot->stringKeyFor($this->pageId, 'i_str_01'));

        $filter = LeafNode::local('name', 'eq', 'paid');
        $resolved = $this->resolvedLeaf($snapshot, $filter);
        $sql = (new SqlFilterCompiler())->compile(
            $resolved,
            new EntryQuery(tenantId: 1, modelId: $this->modelId, filter: $resolved),
            $snapshot,
        )->sql;

        self::assertStringContainsString('p0.i_str_01_k = ', $sql);
        self::assertStringContainsString('p0.i_str_01 = ?', $sql, 'The original predicate stays as the recheck.');
    }

    private function resolvedLeaf(\StarDust\Read\SnapshotEntry $snapshot, LeafNode $leaf): LeafNode
    {
        $descriptor = $snapshot->field($leaf->field->fieldName);
        self::assertNotNull($descriptor);

        return new LeafNode(
            $leaf->operator,
            new \StarDust\Filter\Ast\FieldRef(
                modelName:  'demo',
                fieldName:  $leaf->field->fieldName,
                modelId:    $this->modelId,
                fieldId:    $descriptor->fieldId,
                descriptor: $descriptor,
            ),
            $leaf->value,
        );
    }
}
