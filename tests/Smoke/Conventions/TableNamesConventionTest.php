<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Conventions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use StarDust\Support\TableNames;

/**
 * Guard for ADR 0059: a table or named constraint is written once, in
 * `Support\TableNames`, and everything else reads it from there.
 *
 * The prefix only works if no SQL under `src/` names a fixed table
 * directly. A literal that is missed still runs against the bare name, so
 * it succeeds on an unprefixed installation and on a prefixed one it either
 * fails or, worse, reads another application's table of the same name.
 *
 * The scan looks at string tokens only, so a docblock that names a table
 * is untouched. It flags a bare name that follows a SQL keyword, one that
 * is qualified with a column (`entry_data.id`), and one compared against
 * `table_name`. A name inside an interpolation, such as
 * `{$this->tables->entryData}`, is a variable and never matches.
 *
 * DB-free by design.
 */
final class TableNamesConventionTest extends TestCase
{
    private const SRC = __DIR__ . '/../../../src';

    /** The one file allowed to spell the names. */
    private const OWNER = 'Support' . DIRECTORY_SEPARATOR . 'TableNames.php';

    public function testNoSqlUnderSrcNamesAFixedTableOutsideTableNames(): void
    {
        $offenders = [];
        $checked = 0;

        foreach ($this->sourceFiles() as $file) {
            $relative = substr($file->getPathname(), strlen((string) realpath(self::SRC)) + 1);
            if ($relative === self::OWNER) {
                continue;
            }
            $checked++;

            foreach (self::findings((string) file_get_contents($file->getPathname())) as [$line, $text]) {
                $offenders[] = "{$relative}:{$line}  {$text}";
            }
        }

        self::assertGreaterThan(
            100,
            $checked,
            'Expected to scan the whole engine. The path to src/ has probably broken.',
        );
        self::assertSame(
            [],
            $offenders,
            "SQL names a fixed table directly. Take the name from `Support\\TableNames`"
            . " (`\$this->tables->models`, `->page(\$n)`), so an installation prefix reaches it.",
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function guardedShapes(): array
    {
        return [
            'single quoted after FROM' => ["<?php \$s = 'SELECT 1 FROM stardust_pages WHERE id = 1';"],
            'single quoted after JOIN' => ["<?php \$s = ' JOIN stardust_fields f ON f.id = 1';"],
            'after INTO'               => ["<?php \$s = 'INSERT INTO entry_data (id) VALUES (1)';"],
            'after UPDATE'             => ["<?php \$s = 'UPDATE stardust_models SET name = 1';"],
            'after TABLE'              => ["<?php \$s = 'ALTER TABLE backfill_checkpoints ADD COLUMN x INT';"],
            'after REFERENCES'         => ["<?php \$s = 'FOREIGN KEY (a) REFERENCES stardust_pages (id)';"],
            'after EXISTS'             => ["<?php \$s = 'CREATE TABLE IF NOT EXISTS stardust_sync_queue (id INT)';"],
            'after ON'                 => ["<?php \$s = 'CREATE INDEX i ON stardust_slot_assignments (a)';"],
            'after CONSTRAINT'         => ["<?php \$s = 'CONSTRAINT fk_fields_model FOREIGN KEY (a) REFERENCES x (id)';"],
            'qualified column'         => ["<?php \$s = 'WHERE s.tenant_id = entry_data.tenant_id';"],
            'table_name comparison'    => ["<?php \$s = \"AND table_name = 'stardust_fields'\";"],
            'double quoted'            => ["<?php \$s = \"SELECT 1 FROM stardust_schema_version WHERE id = 1\";"],
            'heredoc body'             => ["<?php \$s = <<<SQL\n    SELECT 1 FROM stardust_export_jobs\n    SQL;"],
            'nowdoc body'              => ["<?php \$s = <<<'SQL'\n    SELECT 1 FROM stardust_import_jobs\n    SQL;"],
            'page table'               => ["<?php \$s = 'SELECT 1 FROM entry_slots_page_1';"],
        ];
    }

    /**
     * The scan has to see every shape it guards, or a green run proves
     * nothing. Each case is the exact form the rewrite removed.
     */
    #[DataProvider('guardedShapes')]
    public function testTheScanFlagsEveryGuardedShape(string $source): void
    {
        self::assertNotSame([], self::findings($source), 'This shape is not detected.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedShapes(): array
    {
        return [
            'interpolated name'     => ["<?php \$s = \"SELECT 1 FROM {\$this->tables->models} WHERE id = 1\";"],
            'concatenated name'     => ["<?php \$s = 'SELECT 1 FROM ' . \$this->tables->pages . ' WHERE id = 1';"],
            'comment'               => ["<?php // reads stardust_pages and entry_data\n\$x = 1;"],
            'docblock'              => ["<?php /** FROM entry_data JOIN stardust_models */\n\$x = 1;"],
            'longer identifier'     => ["<?php \$s = 'CREATE INDEX ix_entry_data_tenant_model ON t (a)';"],
            'prose in a message'    => ["<?php \$s = 'Row in the model table is missing.';"],
        ];
    }

    #[DataProvider('allowedShapes')]
    public function testTheScanLeavesNamesFromTableNamesAndProseAlone(string $source): void
    {
        self::assertSame([], self::findings($source));
    }

    /**
     * @return list<array{int, string}> line number and the text found
     */
    private static function findings(string $php): array
    {
        $names = implode('|', array_map(
            static fn (string $name): string => preg_quote($name, '/'),
            TableNames::BASE_NAMES,
        ));
        $guard = '(?<![a-z0-9_])(?:' . $names . ')(?![a-z])';
        $patterns = [
            '/\b(?:FROM|JOIN|INTO|UPDATE|TABLE|REFERENCES|EXISTS|ON|CONSTRAINT)\s+' . $guard . '/i',
            '/' . $guard . '\.[a-z_]/i',
            "/table_name\\s*=\\s*'" . $guard . "'/i",
        ];

        $found = [];
        foreach (PhpToken::tokenize($php) as $token) {
            if ($token->id !== T_CONSTANT_ENCAPSED_STRING && $token->id !== T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $token->text, $m) === 1) {
                    $found[] = [$token->line, trim((string) preg_replace('/\s+/', ' ', $m[0]))];
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator((string) realpath(self::SRC), RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
