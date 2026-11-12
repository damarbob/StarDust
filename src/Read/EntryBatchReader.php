<?php

declare(strict_types=1);

namespace StarDust\Read;

use InvalidArgumentException;
use StarDust\Exception\TooManyEntryIdsException;
use StarDust\Search\BatchPointReadInterface;
use StarDust\Search\EntrySearchInterface;
use StarDust\Write\TenantId;

/**
 * Reads several entries by id through whichever search driver is active.
 *
 * It holds everything a driver should not repeat: the tenant check, the cap,
 * the type of each id, the collapsing of repeats, the empty list, and the
 * choice between the driver's own batch read and one `get()` per id. A driver
 * that implements {@see BatchPointReadInterface} receives a clean, non-empty
 * list and answers in one round trip. Any other driver, including one written
 * before the capability existed, is asked once per distinct id, so
 * `StarDust::getMany()` works with every driver and only the cost differs.
 *
 * What comes back is what `get()` would return for each id, put in a map:
 * the entries that exist, keyed by id, in the order each id first appeared.
 */
final class EntryBatchReader
{
    /**
     * The most ids one call accepts. One definition with the page size cap,
     * so a batch is bounded by the same number as a page.
     */
    public const MAX_IDS = EntryQuery::MAX_PAGE_SIZE;

    public function __construct(private readonly EntrySearchInterface $driver)
    {
    }

    /**
     * Widened to `array<mixed>` on purpose. PHP enforces only `array`, never
     * `list<int>`, and this method is where untrusted input is checked, so
     * its parameter has to admit what it defends against.
     *
     * @param array<mixed> $entryIds
     * @return array<int, Entry>
     *
     * @throws \StarDust\Exception\InvalidTenantIdException when `$tenantId` is not a positive id
     * @throws TooManyEntryIdsException                     when the list holds more than {@see self::MAX_IDS}
     * @throws InvalidArgumentException                     when an element is not an integer
     */
    public function getMany(int $tenantId, array $entryIds): array
    {
        TenantId::assertValid($tenantId);

        if (count($entryIds) > self::MAX_IDS) {
            throw new TooManyEntryIdsException(
                'getMany() reads at most ' . self::MAX_IDS . ' entries per call; got ' . count($entryIds) . '.'
            );
        }

        // Keyed by id, so a repeat lands on a key that already exists and the
        // first appearance keeps its place.
        $distinct = [];
        foreach ($entryIds as $position => $entryId) {
            if (! is_int($entryId)) {
                throw new InvalidArgumentException(sprintf(
                    'getMany() takes integer ids; the element at position %s is %s.',
                    (string) $position,
                    get_debug_type($entryId),
                ));
            }
            $distinct[$entryId] = $entryId;
        }

        if ($distinct === []) {
            return [];
        }

        $ids = array_values($distinct);

        if ($this->driver instanceof BatchPointReadInterface) {
            return $this->driver->getMany($tenantId, $ids);
        }

        $found = [];
        foreach ($ids as $id) {
            $entry = $this->driver->get($tenantId, $id);
            if ($entry !== null) {
                $found[$id] = $entry;
            }
        }

        return $found;
    }
}
