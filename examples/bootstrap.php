<?php

declare(strict_types=1);

/**
 * Shared setup for every script in examples/.
 *
 * Reads the same STARDUST_DSN / STARDUST_USER / STARDUST_PASS variables
 * as `bin/stardust`, so a terminal already configured for the CLI can
 * run these with no extra ceremony. Returns nothing — it defines
 * helpers and leaves the caller to build its own Config, because the
 * examples deliberately differ in how they configure the engine.
 */

require __DIR__ . '/../vendor/autoload.php';

require __DIR__ . '/lib/Term.php';
require __DIR__ . '/lib/EventRecorder.php';
require __DIR__ . '/lib/LifecycleSnapshot.php';
require __DIR__ . '/lib/LifecycleProbe.php';
require __DIR__ . '/lib/LifecycleView.php';

/**
 * Connect with the attributes StarDust expects.
 *
 * `ERRMODE_EXCEPTION` and `EMULATE_PREPARES => false` are not optional
 * decoration: the engine reads native types back from the driver, and
 * several of its guards distinguish a failed write from a raised error.
 */
function stardust_example_pdo(): PDO
{
    $dsn = getenv('STARDUST_DSN');

    if (! is_string($dsn) || $dsn === '') {
        fwrite(STDERR, <<<'TXT'

        STARDUST_DSN is not set.

        These examples need a MySQL 8.0.13+ database of their own. The
        quickest way to get one:

            docker compose up mysql -d

            export STARDUST_DSN='mysql:host=127.0.0.1;port=3307;dbname=stardust'
            export STARDUST_USER=root
            export STARDUST_PASS=root

        MariaDB will not work — StarDust detects it and refuses to boot.


        TXT);
        exit(1);
    }

    $pass = getenv('STARDUST_PASS');

    try {
        return new PDO(
            $dsn,
            (string) getenv('STARDUST_USER'),
            $pass === false ? '' : $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    } catch (PDOException $e) {
        fwrite(STDERR, "\nCould not connect to {$dsn}\n  {$e->getMessage()}\n\n");
        exit(1);
    }
}

/**
 * Minimal `--flag=value` / `--flag` parsing.
 *
 * Deliberately not a CLI framework — the examples take a handful of
 * options and adding a dependency to parse them would undercut the
 * point that StarDust itself has none.
 *
 * @param  list<string>        $argv
 * @return array<string, string|bool>
 */
function stardust_example_flags(array $argv): array
{
    $flags = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (! str_starts_with($arg, '--')) {
            continue;
        }

        $body = substr($arg, 2);
        $eq   = strpos($body, '=');

        if ($eq === false) {
            $flags[$body] = true;
            continue;
        }

        $flags[substr($body, 0, $eq)] = substr($body, $eq + 1);
    }

    return $flags;
}

/**
 * @param array<string, string|bool> $flags
 */
function stardust_example_int(array $flags, string $name, int $default): int
{
    $raw = $flags[$name] ?? null;

    return is_string($raw) && $raw !== '' ? (int) $raw : $default;
}
