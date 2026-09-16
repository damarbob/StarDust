<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Daemon;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use StarDust\Daemon\AdvisoryLock;
use StarDust\Daemon\LockNamespace;
use StarDust\Liberator\SweepPageLock;

/**
 * ADR 0053 per-installation lock-name qualification.
 *
 * The defect this pins is that MySQL scopes `GET_LOCK` names to the
 * SERVER, not to the database — so two StarDust installations sharing
 * one mysqld (the normal shared-hosting case ADR 0048 made a supported
 * target) contended on `stardust_page_provision` and on every
 * `stardust_sweep_page_{id}`, page ids restarting at 1 in each.
 *
 * Two sibling connections are the only way to see it: `GET_LOCK` is
 * re-entrant within one session, so a single-connection test cannot
 * distinguish "the names differ" from "the same session already holds
 * it". Both directions are asserted deliberately — that different
 * namespaces stop excluding each other is the fix, but that the SAME
 * namespace still excludes is the ADR 0049 guarantee the fix must not
 * cost, and a test for only the first would pass if `qualify()` simply
 * returned a random string.
 */
final class LockNamespaceTest extends TestCase
{
    private PDO $primary;
    private PDO $sibling;

    /** @var list<string> */
    private array $heldNames = [];

    protected function setUp(): void
    {
        $dsn  = getenv('STARDUST_TEST_DSN') ?: '';
        $user = getenv('STARDUST_TEST_USER') ?: '';
        $pass = getenv('STARDUST_TEST_PASS') ?: '';

        if ($dsn === '' || $user === '') {
            self::markTestSkipped('STARDUST_TEST_DSN/STARDUST_TEST_USER must be set.');
        }

        try {
            $this->primary = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->sibling = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            self::fail('Could not connect to test database: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->heldNames as $name) {
            foreach ([$this->sibling ?? null, $this->primary ?? null] as $pdo) {
                if ($pdo instanceof PDO) {
                    try {
                        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                        $stmt->execute([$name]);
                    } catch (\Throwable) {
                        // Connection already gone — MySQL frees the lock with the session.
                    }
                }
            }
        }
    }

    /** The fix: two installations no longer exclude each other. */
    public function testDifferentNamespacesDoNotExclude(): void
    {
        $tenantA = new LockNamespace($this->primary, 'installation_a');
        $tenantB = new LockNamespace($this->sibling, 'installation_b');

        $nameA = $tenantA->qualify('stardust_page_provision');
        $nameB = $tenantB->qualify('stardust_page_provision');
        $this->heldNames[] = $nameA;
        $this->heldNames[] = $nameB;

        self::assertNotSame($nameA, $nameB, 'Distinct namespaces must produce distinct lock names.');

        $held = AdvisoryLock::acquire($this->primary, $nameA, 1);

        // The whole point: B gets its lock while A still holds its own.
        $concurrent = AdvisoryLock::tryAcquire($this->sibling, $nameB, 0);
        self::assertNotNull($concurrent, 'A second installation must not be blocked by the first.');

        $concurrent->release();
        $held->release();
    }

    /** The guarantee the fix must not cost: one installation still serializes. */
    public function testSameNamespaceStillExcludes(): void
    {
        $nameA = (new LockNamespace($this->primary, 'shared_installation'))->qualify('stardust_page_provision');
        $nameB = (new LockNamespace($this->sibling, 'shared_installation'))->qualify('stardust_page_provision');
        $this->heldNames[] = $nameA;

        self::assertSame($nameA, $nameB, 'One namespace must resolve to one name across processes.');

        $held = AdvisoryLock::acquire($this->primary, $nameA, 1);

        self::assertNull(
            AdvisoryLock::tryAcquire($this->sibling, $nameB, 0),
            'Two workers of the SAME installation must still exclude each other.'
        );

        $held->release();
    }

    /**
     * Page ids restart at 1 in every installation, which made
     * `stardust_sweep_page_1` the most collision-prone name in the
     * engine. Driven through `SweepPageLock` rather than `LockNamespace`
     * directly, so the wiring is covered and not just the qualifier.
     */
    public function testSweepPageLockIsPerInstallation(): void
    {
        $lockA = new SweepPageLock($this->primary, new LockNamespace($this->primary, 'installation_a'));
        $lockB = new SweepPageLock($this->sibling, new LockNamespace($this->sibling, 'installation_b'));

        $this->heldNames[] = (new LockNamespace($this->primary, 'installation_a'))->qualify('stardust_sweep_page_1');
        $this->heldNames[] = (new LockNamespace($this->sibling, 'installation_b'))->qualify('stardust_sweep_page_1');

        $heldA = $lockA->tryAcquire(1);
        self::assertNotNull($heldA);

        $heldB = $lockB->tryAcquire(1);
        self::assertNotNull($heldB, 'Two installations must each sweep their own page 1.');

        $heldB->release();
        $heldA->release();
    }

    /**
     * `SweepPageLock` with no namespace keeps the bare literal — the
     * shape every pre-0053 fixture asserts on.
     */
    public function testUnqualifiedSweepLockKeepsLiteralName(): void
    {
        $this->heldNames[] = 'stardust_sweep_page_7';

        $held = (new SweepPageLock($this->primary))->tryAcquire(7);
        self::assertNotNull($held);

        // Proven by the literal excluding a sibling asking for the same literal.
        $stmt = $this->sibling->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute(['stardust_sweep_page_7', 0]);
        self::assertSame('0', (string) $stmt->fetchColumn());

        $held->release();
    }

    /** Ops greppability: the base name survives as a prefix. */
    public function testQualifiedNameKeepsBaseAsPrefixAndFitsMysqlLimit(): void
    {
        $name = (new LockNamespace($this->primary, str_repeat('x', 64)))
            ->qualify('stardust_sweep_page_9223372036854775807');

        self::assertStringStartsWith('stardust_sweep_page_9223372036854775807', $name);
        self::assertLessThanOrEqual(64, strlen($name), 'GET_LOCK rejects a name longer than 64 characters.');
    }

    /**
     * The default discriminator is the schema, which is what makes the
     * fix work with no configuration at all.
     */
    public function testDefaultNamespaceDerivesFromDatabaseName(): void
    {
        $schema = (string) $this->primary->query('SELECT DATABASE()')->fetchColumn();
        self::assertNotSame('', $schema, 'Test DSN must select a schema for this assertion to mean anything.');

        $derived  = (new LockNamespace($this->primary))->qualify('stardust_page_provision');
        $explicit = (new LockNamespace($this->primary, $schema))->qualify('stardust_page_provision');

        self::assertSame($explicit, $derived);
        self::assertNotSame('stardust_page_provision', $derived);
    }
}
