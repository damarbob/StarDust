<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Read;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StarDust\Read\StringKey;

/**
 * What the data dictionary has to show before the compiler will trust a key.
 *
 * DB-free. `StringKeyResolutionTest` covers the same rules against real DDL,
 * and this class covers the ones DDL cannot produce, such as a collation name
 * that is not a plain identifier.
 */
final class StringKeyTest extends TestCase
{
    public function testTheHeadIsTheKeyWidthMinusTheDigest(): void
    {
        $key = StringKey::fromDictionary('i_str_01_k', 'ix_p_i_str_01', 48, 'utf8mb4_0900_ai_ci');

        self::assertNotNull($key);
        self::assertSame('i_str_01_k', $key->keyColumn);
        self::assertSame('ix_p_i_str_01', $key->indexName);
        self::assertSame(32, $key->headBytes);
        self::assertSame('utf8mb4_0900_ai_ci', $key->collation);
    }

    public function testAPageBuiltWithAnotherHeadIsServedAsItIs(): void
    {
        self::assertSame(64, StringKey::fromDictionary('k', 'i', 80, 'utf8mb4_0900_ai_ci')?->headBytes);
        self::assertSame(1, StringKey::fromDictionary('k', 'i', 17, 'utf8mb4_0900_ai_ci')?->headBytes);
    }

    public function testAKeyNoWiderThanItsDigestHasNoHead(): void
    {
        self::assertNull(StringKey::fromDictionary('k', 'i', 16, 'utf8mb4_0900_ai_ci'));
        self::assertNull(StringKey::fromDictionary('k', 'i', 0, 'utf8mb4_0900_ai_ci'));
    }

    public function testNoIndexOrNoCollationMeansNoKey(): void
    {
        self::assertNull(StringKey::fromDictionary('k', null, 48, 'utf8mb4_0900_ai_ci'));
        self::assertNull(StringKey::fromDictionary('k', 'i', 48, null));
    }

    /** @return iterable<string, array{string}> */
    public static function hostileCollations(): iterable
    {
        yield 'a second statement' => ['utf8mb4_0900_ai_ci; DROP TABLE entry_data'];
        yield 'a space' => ['utf8mb4_0900_ai_ci '];
        yield 'a quote' => ["utf8mb4_0900_ai_ci'"];
        yield 'a placeholder' => ['utf8mb4_0900_ai_ci?'];
        yield 'empty' => [''];
    }

    #[DataProvider('hostileCollations')]
    public function testACollationNameThatIsNotAPlainIdentifierIsIgnored(string $collation): void
    {
        self::assertNull(StringKey::fromDictionary('k', 'i', 48, $collation));
    }
}
