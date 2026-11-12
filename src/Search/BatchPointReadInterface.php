<?php

declare(strict_types=1);

namespace StarDust\Search;

use StarDust\Read\Entry;

/**
 * An optional capability of a search driver: reading several entries by id
 * in one round trip.
 *
 * It is an interface of its own rather than a method of
 * {@see EntrySearchInterface}, because that interface is public and a new
 * method would break every driver written against it. A driver that does not
 * implement this one still serves `StarDust::getMany()`, which then calls
 * {@see EntrySearchInterface::get()} once per id.
 *
 * `StarDust::getMany()` validates the input before it reaches a driver, so
 * an implementation may assume the ids are a non-empty list of integers with
 * no repeats and within the engine's cap. An implementation owes the caller
 * the same answers `get()` gives for each id: an entry that is missing,
 * belongs to another tenant or is soft-deleted is left out, and a model being
 * deleted contributes nothing.
 */
interface BatchPointReadInterface
{
    /**
     * Reads the entries with the given ids for one tenant.
     *
     * @param list<int> $entryIds
     * @return array<int, Entry> the entries that exist, keyed by id, in the
     *                           order the ids were given
     */
    public function getMany(int $tenantId, array $entryIds): array;
}
