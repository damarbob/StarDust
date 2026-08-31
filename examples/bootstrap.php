<?php

declare(strict_types=1);

/**
 * Shared setup for every script in examples/.
 *
 * Reads the same STARDUST_DSN / STARDUST_USER / STARDUST_PASS variables
 * as `bin/stardust`, so a terminal already configured for the CLI can
 * run these with no extra ceremony — and additionally loads them from a
 * git-ignored `.env` at the repo root when they are not already set. Returns nothing — it defines
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
 * Load `.env` from the repo root into the process environment.
 *
 * StarDust itself has no dotenv dependency and reads nothing but the
 * process environment — a framework-neutral library has no business
 * deciding where a host application keeps its configuration. That rule
 * binds `src/`, not a convenience script, and requiring three exports
 * before a beginner can run a demonstration is friction for its own
 * sake. So the examples read the file; the engine still does not.
 *
 * Real environment variables always win, so an explicit
 * `STARDUST_DSN=... php examples/01-field-lifecycle.php` still
 * overrides the file. Missing or unreadable `.env` is not an error.
 */
function stardust_example_load_dotenv(): void
{
    $path = \dirname(__DIR__) . '/.env';

    if (! is_readable($path)) {
        return;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eq));

        // Only StarDust's own variables. The file may hold unrelated
        // keys — this one still carries CodeIgniter 4 leftovers from
        // the 0.2.x line — and a loader that exported everything it
        // found would be a surprising thing for an example to do.
        if (! str_starts_with($key, 'STARDUST_')) {
            continue;
        }

        // getenv() is the authority here, not $_ENV: variables_order
        // need not include E, in which case $_ENV is empty and every
        // real export would look unset and be overwritten.
        if (getenv($key) !== false) {
            continue;
        }

        $value = trim(substr($line, $eq + 1));

        // Strip one matched pair of surrounding quotes. Note the DSN
        // contains `;`, which is NOT a comment in this format — trailing
        // `#` comments are deliberately not supported, so a value is
        // taken verbatim to end of line.
        if (\strlen($value) >= 2
            && ($value[0] === '"' || $value[0] === "'")
            && $value[-1] === $value[0]
        ) {
            $value = substr($value, 1, -1);
        }

        putenv("{$key}={$value}");
    }
}

stardust_example_load_dotenv();

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

        These examples need a MySQL 8.0.13+ database of their own.
        Either put the three variables in a `.env` at the repo root:

            STARDUST_DSN=mysql:host=127.0.0.1;port=3307;dbname=stardust
            STARDUST_USER=root
            STARDUST_PASS=root

        ...or export them. If you have no database yet:

            docker compose up mysql -d

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
