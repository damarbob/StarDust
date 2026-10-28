<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use StarDust\Support\StringKeyLayout;

/**
 * The SQL that builds and searches a string slot's key, pinned as text.
 *
 * The expected statements are written out here by hand rather than composed
 * from the class's own constants, so a change to the layout shows up as a
 * failing line. That matters because a lookup that is slightly wrong returns
 * wrong rows and raises nothing. What those statements return against real
 * data is `Search\StringKeyMatchingTest`'s subject.
 */
final class StringKeyLayoutTest extends TestCase
{
    private const COLLATION = 'utf8mb4_0900_ai_ci';

    /** The weight string of a bound value, cast to the page collation. */
    private const WEIGHT = 'WEIGHT_STRING(CONVERT(? USING utf8mb4) COLLATE utf8mb4_0900_ai_ci)';

    public function testKeyColumnNamesRoundTrip(): void
    {
        self::assertSame('i_str_01_k', StringKeyLayout::keyColumn('i_str_01'));
        self::assertSame('i_str_25_k', StringKeyLayout::keyColumn('i_str_25'));
        self::assertSame('i_str_07', StringKeyLayout::slotColumn('i_str_07_k'));
    }

    public function testOnlyAStringSlotHasAKeyColumn(): void
    {
        foreach (['i_int_01', 'i_str_1', 'i_str_01_k', 'str_01', ''] as $notAStringSlot) {
            try {
                StringKeyLayout::keyColumn($notAStringSlot);
                self::fail("'{$notAStringSlot}' must not get a key column.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (['i_str_01', 'i_int_01_k', 'i_str_01_kk', 'x_i_str_01_k', 'i_str_01_k '] as $notAKeyColumn) {
            self::assertNull(StringKeyLayout::slotColumn($notAKeyColumn), "'{$notAKeyColumn}'");
        }
    }

    public function testKeyWidthIsTheHeadPlusTheDigest(): void
    {
        self::assertSame(48, StringKeyLayout::keyWidth());
        self::assertSame(80, StringKeyLayout::keyWidth(64));
        self::assertSame(32, StringKeyLayout::headBytes(48));
        self::assertSame(64, StringKeyLayout::headBytes(80));
    }

    public function testAHeadMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StringKeyLayout::headBytes(16);
    }

    public function testTheColumnDefinitionIsThePaddedHeadFollowedByTheDigest(): void
    {
        self::assertSame(
            'i_str_01_k VARBINARY(48) GENERATED ALWAYS AS ('
            . "CONCAT(RPAD(LEFT(WEIGHT_STRING(i_str_01), 32), 32, UNHEX('00')),"
            . ' LEFT(UNHEX(SHA2(WEIGHT_STRING(i_str_01), 256)), 16))'
            . ') STORED',
            StringKeyLayout::columnDdl('i_str_01'),
        );
    }

    public function testEqualityLooksUpTheSamePaddedHeadAndDigestAndBindsTheValueTwice(): void
    {
        $p = StringKeyLayout::equals('p0.i_str_01_k', 'PAID', 32, self::COLLATION);

        self::assertSame(
            'p0.i_str_01_k = CONCAT(RPAD(LEFT(' . self::WEIGHT . ", 32), 32, UNHEX('00')),"
            . ' LEFT(UNHEX(SHA2(' . self::WEIGHT . ', 256)), 16))',
            $p['sql'],
        );
        self::assertSame(['PAID', 'PAID'], $p['bindings']);
    }

    public function testAnInListSearchesEachElementsKeyAndBindsThemInOrder(): void
    {
        $p = StringKeyLayout::inList('k', ['a', 'b', 'c'], 32, self::COLLATION);

        $key = 'CONCAT(RPAD(LEFT(' . self::WEIGHT . ", 32), 32, UNHEX('00')),"
            . ' LEFT(UNHEX(SHA2(' . self::WEIGHT . ', 256)), 16))';
        self::assertSame("k IN ({$key}, {$key}, {$key})", $p['sql']);
        self::assertSame(['a', 'a', 'b', 'b', 'c', 'c'], $p['bindings']);
    }

    public function testAnInListNeedsAnElement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StringKeyLayout::inList('k', [], 32, self::COLLATION);
    }

    public function testAPrefixIsBoundedByTheUnpaddedHeadAndFilledToTheFullKeyWidth(): void
    {
        $p = StringKeyLayout::prefix('k', 'ab_c', 32, self::COLLATION);

        $head = 'LEFT(' . self::WEIGHT . ', 32)';
        self::assertSame("k BETWEEN {$head} AND CONCAT({$head}, REPEAT(UNHEX('FF'), 48))", $p['sql']);
        self::assertSame(['ab_c', 'ab_c'], $p['bindings'], 'The raw prefix, with no LIKE escaping and no wildcard.');
        self::assertStringNotContainsString('RPAD', $p['sql'], 'A padded lower bound would skip values that continue with a low byte.');
    }

    public function testALowerBoundIsPaddedWithZeroBytes(): void
    {
        $p = StringKeyLayout::atLeast('k', 'm', 32, self::COLLATION);

        self::assertSame('k >= RPAD(LEFT(' . self::WEIGHT . ", 32), 32, UNHEX('00'))", $p['sql']);
        self::assertSame(['m'], $p['bindings']);
    }

    public function testAnUpperBoundIsPaddedAndThenFilledAcrossTheDigest(): void
    {
        $p = StringKeyLayout::atMost('k', 'm', 32, self::COLLATION);

        self::assertSame(
            'k <= CONCAT(RPAD(LEFT(' . self::WEIGHT . ", 32), 32, UNHEX('00')), REPEAT(UNHEX('FF'), 16))",
            $p['sql'],
        );
        self::assertSame(['m'], $p['bindings']);
    }

    public function testBetweenCombinesBothBoundsAndBindsLowerBeforeUpper(): void
    {
        $p = StringKeyLayout::between('k', 'low', 'high', 32, self::COLLATION);

        self::assertSame(
            StringKeyLayout::atLeast('k', 'low', 32, self::COLLATION)['sql']
            . ' AND '
            . StringKeyLayout::atMost('k', 'high', 32, self::COLLATION)['sql'],
            $p['sql'],
        );
        self::assertSame(['low', 'high'], $p['bindings']);
    }

    public function testTheHeadWidthIsTheCallersAndNeverTheConstant(): void
    {
        $eq = StringKeyLayout::equals('k', 'x', 64, self::COLLATION)['sql'];
        self::assertStringContainsString(', 64), 64, UNHEX(', $eq);
        self::assertStringNotContainsString(', 32)', $eq);

        $prefix = StringKeyLayout::prefix('k', 'x', 64, self::COLLATION)['sql'];
        self::assertStringContainsString("REPEAT(UNHEX('FF'), 80)", $prefix);

        $upper = StringKeyLayout::atMost('k', 'x', 64, self::COLLATION)['sql'];
        self::assertStringContainsString("REPEAT(UNHEX('FF'), 16)", $upper, 'The fill past the head is the digest width, whatever the head.');
    }

    public function testNullPredicatesNeedNoWeightString(): void
    {
        self::assertSame('k IS NULL', StringKeyLayout::isNull('k'));
        self::assertSame('k IS NOT NULL', StringKeyLayout::isNotNull('k'));
    }

    public function testEveryPlaceholderTakesOneBinding(): void
    {
        $lookups = [
            StringKeyLayout::equals('k', 'v', 32, self::COLLATION),
            StringKeyLayout::inList('k', ['v', 'w'], 32, self::COLLATION),
            StringKeyLayout::prefix('k', 'v', 32, self::COLLATION),
            StringKeyLayout::atLeast('k', 'v', 32, self::COLLATION),
            StringKeyLayout::atMost('k', 'v', 32, self::COLLATION),
            StringKeyLayout::between('k', 'v', 'w', 32, self::COLLATION),
        ];

        foreach ($lookups as $p) {
            self::assertSame(substr_count($p['sql'], '?'), count($p['bindings']), $p['sql']);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function hostileCollations(): iterable
    {
        yield 'a second statement' => ['utf8mb4_0900_ai_ci; DROP TABLE entry_data'];
        yield 'a space' => ['utf8mb4_0900_ai_ci '];
        yield 'a quote' => ["utf8mb4_0900_ai_ci'"];
        yield 'a placeholder' => ['utf8mb4_0900_ai_ci?'];
        yield 'a backtick' => ['`utf8mb4_0900_ai_ci`'];
        yield 'empty' => [''];
        yield 'a newline' => ["utf8mb4_0900_ai_ci\n"];
    }

    #[DataProvider('hostileCollations')]
    public function testACollationNameThatIsNotAPlainIdentifierNeverReachesSql(string $collation): void
    {
        self::assertFalse(StringKeyLayout::isSafeCollation($collation));

        $this->expectException(InvalidArgumentException::class);
        StringKeyLayout::equals('k', 'x', 32, $collation);
    }

    public function testBothEnginesCollationsAreSafeNames(): void
    {
        self::assertTrue(StringKeyLayout::isSafeCollation('utf8mb4_0900_ai_ci'));
        self::assertTrue(StringKeyLayout::isSafeCollation('utf8mb4_unicode_520_nopad_ci'));
    }

    /**
     * The anti-drift scan. The weight string and the digest are built in
     * `StringKeyLayout` and nowhere else, in the same spirit as
     * {@see \StarDust\Tests\Smoke\DialectTest}. A second copy of either would
     * be a second place for the layout to be slightly wrong.
     */
    public function testTheWeightStringAndTheDigestAreBuiltInExactlyOnePlace(): void
    {
        $src = (string) realpath(__DIR__ . '/../../../src');
        $owner = $src . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'StringKeyLayout.php';

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS));
        $scanned = 0;
        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $scanned++;
            if ($file->getRealPath() === $owner) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            foreach (['WEIGHT_STRING(', 'SHA2('] as $construct) {
                self::assertFalse(
                    stripos($source, $construct),
                    "{$file->getFilename()} must build keys through StringKeyLayout and not name {$construct}.",
                );
            }
        }
        self::assertGreaterThan(50, $scanned, 'The scan must actually be walking src/.');

        $layout = (string) file_get_contents($owner);
        self::assertStringContainsString('WEIGHT_STRING(', $layout);
        self::assertStringContainsString('SHA2(', $layout);
    }

    public function testTheTestServerCanComputeTheDigest(): void
    {
        $dsn  = getenv('STARDUST_TEST_DSN') ?: '';
        $user = getenv('STARDUST_TEST_USER') ?: '';
        $pass = getenv('STARDUST_TEST_PASS') ?: '';

        if ($dsn === '' || $user === '') {
            self::markTestSkipped('STARDUST_TEST_DSN and STARDUST_TEST_USER must be set for smoke tests.');
        }

        try {
            $pdo = new PDO($dsn, $user, $pass, TestPdo::options());
        } catch (PDOException $e) {
            self::fail('Could not connect to test database: ' . $e->getMessage());
        }

        self::assertTrue(StringKeyLayout::digestAvailable($pdo));
    }
}
