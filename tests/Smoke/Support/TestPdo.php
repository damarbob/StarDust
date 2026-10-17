<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use PDO;

/**
 * The one set of PDO options every smoke-suite connection opens with.
 *
 * The engine takes an injected PDO and supports both prepare modes, so
 * the suite has to be runnable under both: native prepares are the
 * default, and `STARDUST_TEST_EMULATE_PREPARES=1` switches every
 * connection the suite opens to emulated ones, which is what the
 * dedicated emulated CI cell sets. It used to be thirteen pasted option
 * arrays each hardcoding `ATTR_EMULATE_PREPARES => false`; a copy left
 * behind would silently keep one connection native in the emulated run,
 * so a new connection site should take its options from here.
 *
 * A site that needs a different attribute (an `ERRMODE_SILENT`
 * connection, say) overlays it with `[...] + TestPdo::options()`, so the
 * prepare mode still follows the environment.
 *
 * `Search\EmulatedPreparesTest` deliberately does **not** use this: it
 * pins emulation on regardless of the environment, so the default native
 * run still covers it.
 */
final class TestPdo
{
    /**
     * @return array<int, mixed>
     */
    public static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => self::emulatePrepares(),
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
    }

    /** True only when `STARDUST_TEST_EMULATE_PREPARES` is exactly `1`. */
    public static function emulatePrepares(): bool
    {
        return getenv('STARDUST_TEST_EMULATE_PREPARES') === '1';
    }
}
