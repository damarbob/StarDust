<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use PHPUnit\Framework\TestCase;
use StarDust\Support\ArtifactDirectory;

/**
 * {@see ArtifactDirectory} — the shared race-tolerant "make sure this
 * directory exists" helper behind the export artifact factory, the
 * bulk-ingest submitter, and the ADR 0051 disk probe.
 *
 * DB-free, at the tests/Smoke root, following the `UuidV4Test`
 * precedent for a `src/Support/` class.
 */
final class ArtifactDirectoryTest extends TestCase
{
    /** @var list<string> */
    private array $made = [];

    protected function tearDown(): void
    {
        // Deepest first, so nested directories come out cleanly.
        usort($this->made, static fn (string $a, string $b) => strlen($b) <=> strlen($a));
        foreach ($this->made as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        $this->made = [];
    }

    private function tempPath(string $suffix = ''): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'stardust-artdir-' . bin2hex(random_bytes(6)) . $suffix;
        $this->made[] = $path;
        return $path;
    }

    public function testCreatesAMissingDirectory(): void
    {
        $dir = $this->tempPath();
        self::assertDirectoryDoesNotExist($dir);

        self::assertTrue((new ArtifactDirectory($dir))->ensure());
        self::assertDirectoryExists($dir);
    }

    public function testCreatesNestedPathsRecursively(): void
    {
        $base = $this->tempPath();
        $deep = $base . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b';
        $this->made[] = $base . DIRECTORY_SEPARATOR . 'a';
        $this->made[] = $deep;

        self::assertTrue((new ArtifactDirectory($deep))->ensure());
        self::assertDirectoryExists($deep);
    }

    public function testIsIdempotentOnAnExistingDirectory(): void
    {
        $dir = $this->tempPath();
        $artifactDir = new ArtifactDirectory($dir);

        self::assertTrue($artifactDir->ensure());
        self::assertTrue($artifactDir->ensure(), 're-running on an existing directory is success');
        self::assertDirectoryExists($dir);
    }

    public function testReturnsFalseWhenTheDirectoryCannotBeCreated(): void
    {
        // A path whose parent is a FILE, not a directory — mkdir
        // cannot succeed and neither can the is_dir() re-check, so
        // this is the genuine false case rather than a simulated one.
        $file = $this->tempPath('.txt');
        file_put_contents($file, 'not a directory');

        $impossible = $file . DIRECTORY_SEPARATOR . 'child';

        self::assertFalse((new ArtifactDirectory($impossible))->ensure());

        @unlink($file);
    }

    public function testPathIsReportedVerbatim(): void
    {
        $dir = $this->tempPath();
        self::assertSame($dir, (new ArtifactDirectory($dir))->path());
    }
}
