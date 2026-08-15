<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Conventions;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use StarDust\Config\Config;

/**
 * Backwards-compatibility guard for the `Config` constructor.
 *
 * `Config` is the single construction-time DTO (ADR 0026), and each
 * phase appends new tuning fields to it. Appending is safe. Reordering,
 * renaming, inserting, or removing a parameter is not: every caller
 * passing positional arguments silently binds the wrong value, with no
 * type error to catch it whenever the neighbouring types happen to
 * agree — and the daemon-tuning fields are overwhelmingly `int`.
 *
 * This asserts a frozen *prefix* rather than the whole list, so a
 * future phase appending a parameter needs no edit here. Only a
 * change to existing positions fails.
 *
 * Note the deliberate asymmetry in `Config`: property declarations are
 * grouped by subsystem for readability, while constructor parameters
 * are strictly append-ordered. The constructor is the compatibility
 * surface, and it is the one this guard watches.
 *
 * DB-free by design.
 */
final class ConfigAppendOnlyTest extends TestCase
{
    /**
     * Constructor parameters in declaration order, frozen as of
     * Phase 8 plus the three later single-field appends.
     *
     * NEVER reorder or remove an entry. Appending here is optional —
     * the assertion only covers this prefix.
     *
     * @var list<string>
     */
    private const FROZEN_PREFIX = [
        'pdo',
        'logger',
        'clock',
        'artifactDir',
        'watcherPollIntervalSeconds',
        'watcherCapacityThreshold',
        'watcherProvisionLockTimeoutSeconds',
        'cardinalityIntervalSeconds',
        'cardinalitySelectivityThreshold',
        'cardinalityRowFloor',
        'cardinalityDistinctFloor',
        'reconcilerChunkSize',
        'reconcilerInterChunkDelayMicros',
        'reconcilerCapacityWaitMillis',
        'pidFileDir',
        'liberatorIdleIntervalSeconds',
        'liberatorBatchSize',
        'liberatorChunkSize',
        'liberatorInterChunkDelayMicros',
        'liberatorDeadlockRetryBudget',
        'chroniclerIdleIntervalSeconds',
        'chroniclerLeaseTimeoutSeconds',
        'chroniclerPageSize',
        'chroniclerInterChunkDelayMicros',
        'chroniclerDeadlockRetryBudget',
        'chroniclerSkipCountCap',
        'chroniclerArtifactSizeCapBytes',
        'chroniclerArtifactTtlSeconds',
        'chroniclerOrphanedPartialTtlSeconds',
        'chroniclerLowDiskThresholdPct',
        'chroniclerPerTenantActiveCap',
        'chroniclerDbDisconnectBackoffSeconds',
        'searchDriver',
        'queryFilterLimits',
        'pdoConnector',
        'reconcilerImportLeaseTimeoutSeconds',
        'cardinalityJitterSeconds',
    ];

    public function testConstructorParametersAreOnlyEverAppended(): void
    {
        $actual = $this->constructorParameterNames();

        self::assertGreaterThanOrEqual(
            count(self::FROZEN_PREFIX),
            count($actual),
            'A Config constructor parameter was removed. Existing positions are frozen —'
            . ' deprecate in place rather than deleting.',
        );

        self::assertSame(
            self::FROZEN_PREFIX,
            array_slice($actual, 0, count(self::FROZEN_PREFIX)),
            'The Config constructor prefix changed. New tuning fields must be APPENDED'
            . ' after the last existing parameter — reordering or inserting silently'
            . ' rebinds every positional caller. See CLAUDE.md, "Phased build discipline".',
        );
    }

    /** @return list<string> */
    private function constructorParameterNames(): array
    {
        $names = [];
        foreach ((new ReflectionMethod(Config::class, '__construct'))->getParameters() as $parameter) {
            $names[] = $parameter->getName();
        }

        return $names;
    }
}
