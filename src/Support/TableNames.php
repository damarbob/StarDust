<?php

declare(strict_types=1);

namespace StarDust\Support;

use InvalidArgumentException;

/**
 * The one definition of every table and named constraint the engine
 * creates or reads, with the installation's optional prefix applied
 * (ADR 0059).
 *
 * A prefix lets StarDust share a database with another application, and
 * lets two installations share one. It is joined without a separator, as
 * WordPress does, so `sd_` gives `sd_entry_data`. With no prefix every
 * name is the bare one, which is what installations made before the
 * prefix existed already have, so `new TableNames()` is the default
 * wherever a class accepts one.
 *
 * ## What carries the prefix
 *
 * Every table, including `entry_data`, `backfill_checkpoints` and the page
 * tables (`{prefix}entry_slots_page_N`), and the five constraints the DDL
 * names explicitly. A foreign key name is scoped to the schema on both
 * engines and a `CHECK` name is scoped to the schema on MySQL, so two
 * installations in one schema would collide on them even with every table
 * renamed. An index name belongs to its table and is left alone, and so
 * are the page tables' own constraint and index names, which are built
 * from the (already prefixed) page table name.
 *
 * ## The prefix is validated, and short
 *
 * It must match `[a-z][a-z0-9_]{0,15}`. A name is interpolated into SQL,
 * never bound, so the character set is what stops a prefix being an
 * injection point. Lowercase only, because table names are case sensitive
 * on Linux and not on Windows, and `SD_` and `sd_` would be two
 * installations on one and a single installation on the other. Sixteen is
 * arithmetic. The longest identifier the engine builds is a page index,
 * `ix_{prefix}entry_slots_page_{N}_i_str_NN`, which is 29 characters
 * plus the prefix plus the digits of `N`. With a 16 character prefix and
 * a 19 digit BIGINT page number that is exactly MySQL's limit of 64, so
 * no runtime length check exists.
 *
 * Do not write one of the {@see self::BASE_NAMES} in SQL anywhere else.
 * `TableNamesConventionTest` fails the build when one follows a SQL
 * keyword outside this file.
 */
final class TableNames
{
    public const PREFIX_PATTERN = '/^[a-z][a-z0-9_]{0,15}$/';

    private const PAGE_BASE = 'entry_slots_page_';

    /**
     * Every fixed table name and named constraint, bare. The convention
     * test reads this list, so a name added here is guarded at once.
     */
    public const BASE_NAMES = [
        'entry_data',
        'backfill_checkpoints',
        'stardust_sync_queue',
        'stardust_models',
        'stardust_fields',
        'stardust_pages',
        'stardust_slot_assignments',
        'stardust_schema_version',
        'stardust_export_jobs',
        'stardust_import_jobs',
        'stardust_reconciler_dlq',
        'stardust_advisory_schedule',
        self::PAGE_BASE,
        'fk_fields_model',
        'fk_slot_assignments_page',
        'fk_slot_assignments_field',
        'ck_schema_version_singleton',
        'ck_advisory_schedule_singleton',
    ];

    /** The prefix as given, `''` when there is none. */
    public readonly string $prefix;

    public readonly string $entryData;
    public readonly string $backfillCheckpoints;
    public readonly string $syncQueue;
    public readonly string $models;
    public readonly string $fields;
    public readonly string $pages;
    public readonly string $slotAssignments;
    public readonly string $schemaVersion;
    public readonly string $exportJobs;
    public readonly string $importJobs;
    public readonly string $reconcilerDlq;
    public readonly string $advisorySchedule;

    public readonly string $fkFieldsModel;
    public readonly string $fkSlotAssignmentsPage;
    public readonly string $fkSlotAssignmentsField;
    public readonly string $ckSchemaVersionSingleton;
    public readonly string $ckAdvisoryScheduleSingleton;

    /**
     * @param string|null $prefix `Config::$tablePrefix`. `null` and `''`
     *        both mean none.
     *
     * @throws InvalidArgumentException when the prefix is not
     *         `[a-z][a-z0-9_]{0,15}`.
     */
    public function __construct(?string $prefix = null)
    {
        $prefix ??= '';

        if ($prefix !== '' && preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new InvalidArgumentException(
                "Config::\$tablePrefix '{$prefix}' is not valid. A prefix is 1 to 16 characters,"
                . ' starts with a lowercase letter, and holds only lowercase letters, digits'
                . " and underscores, for example 'sd_'."
            );
        }

        $this->prefix                      = $prefix;
        $this->entryData                   = $prefix . 'entry_data';
        $this->backfillCheckpoints         = $prefix . 'backfill_checkpoints';
        $this->syncQueue                   = $prefix . 'stardust_sync_queue';
        $this->models                      = $prefix . 'stardust_models';
        $this->fields                      = $prefix . 'stardust_fields';
        $this->pages                       = $prefix . 'stardust_pages';
        $this->slotAssignments             = $prefix . 'stardust_slot_assignments';
        $this->schemaVersion               = $prefix . 'stardust_schema_version';
        $this->exportJobs                  = $prefix . 'stardust_export_jobs';
        $this->importJobs                  = $prefix . 'stardust_import_jobs';
        $this->reconcilerDlq               = $prefix . 'stardust_reconciler_dlq';
        $this->advisorySchedule            = $prefix . 'stardust_advisory_schedule';
        $this->fkFieldsModel               = $prefix . 'fk_fields_model';
        $this->fkSlotAssignmentsPage       = $prefix . 'fk_slot_assignments_page';
        $this->fkSlotAssignmentsField      = $prefix . 'fk_slot_assignments_field';
        $this->ckSchemaVersionSingleton    = $prefix . 'ck_schema_version_singleton';
        $this->ckAdvisoryScheduleSingleton = $prefix . 'ck_advisory_schedule_singleton';
    }

    /** The extension page table for a page number, `entry_slots_page_N` under no prefix. */
    public function page(int $number): string
    {
        return $this->prefix . self::PAGE_BASE . $number;
    }

    /**
     * Whether `$tableName` has the shape of this installation's page
     * table. Callers that are about to put a table name into DDL check it
     * first, since a name is interpolated rather than bound.
     */
    public function isPageTable(string $tableName): bool
    {
        return preg_match(
            '/^' . preg_quote($this->prefix . self::PAGE_BASE, '/') . '[1-9]\d*$/',
            $tableName
        ) === 1;
    }

    /**
     * A daemon's pid and shutdown file name for this installation.
     * Two installations that share a `pidFileDir` hold different files
     * under different prefixes. With no prefix this is `$daemonName`.
     */
    public function daemon(string $daemonName): string
    {
        return $this->prefix . $daemonName;
    }
}
