<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Conventions;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Guard for the no-inheritance stance: every class under `src/` is
 * `final`.
 *
 * CLAUDE.md states this for daemon collaborators ("every daemon
 * collaborator is `final`; no abstract base classes"), and the whole
 * engine already honours it — 146 final classes, no abstract ones.
 * Composition plus single-method interfaces is the established shape,
 * and the seams are constructor-injected collaborators rather than
 * subclass hooks.
 *
 * The guard exists because the drift is one careless keyword away, and
 * an abstract base is very hard to remove once anything extends it.
 * Tripping this test is not automatically wrong — it means the change
 * deserves a deliberate decision (and an ADR) rather than arriving
 * unnoticed inside an unrelated commit.
 *
 * Uses reflection rather than a source regex, so attributes, multi-line
 * declarations, and formatting cannot fool it.
 *
 * DB-free by design.
 */
final class FinalClassGuardTest extends TestCase
{
    private const SRC = __DIR__ . '/../../../src';

    private const NAMESPACE_PREFIX = 'StarDust\\';

    public function testEveryClassUnderSrcIsFinal(): void
    {
        $checked = 0;

        foreach ($this->classNames() as $class) {
            $reflection = new ReflectionClass($class);

            // Interfaces and enums cannot be final and are the
            // sanctioned extension points.
            if ($reflection->isInterface() || $reflection->isEnum()) {
                continue;
            }

            $checked++;

            self::assertTrue(
                $reflection->isFinal(),
                "{$class} is not final. StarDust composes rather than inherits —"
                . ' inject a collaborator or add a single-method interface instead of'
                . ' opening a class for extension. See CLAUDE.md, "Phased build discipline".',
            );
        }

        self::assertGreaterThan(
            100,
            $checked,
            'Expected to inspect the whole engine; the PSR-4 path mapping has probably broken.',
        );
    }

    /**
     * Every class-like symbol under `src/`, resolved from its path.
     *
     * PSR-4 guarantees one declaration per file and a filename that
     * matches the symbol, so the path is an exact FQCN.
     *
     * @return list<class-string>
     */
    private function classNames(): array
    {
        $classes = [];
        $root    = (string) realpath(self::SRC);

        /** @var iterable<SplFileInfo> $iter */
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iter as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr((string) $file->getRealPath(), strlen($root) + 1);
            $relative = substr($relative, 0, -strlen('.php'));

            /** @var class-string $class */
            $class = self::NAMESPACE_PREFIX . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}
