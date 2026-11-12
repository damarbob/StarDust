# Custom search drivers

`StarDust\Search\EntrySearchInterface` is the swappable seam. The engine ships with a `MysqlNativeDriver` that wraps the bounded-read path; inject any other implementation through `Config`:

```php
use StarDust\Config\Config;
use StarDust\Search\EntrySearchInterface;

final class MeilisearchDriver implements EntrySearchInterface { /* ... */ }

$engine = new StarDust(new Config(
    pdo:          $pdo,
    searchDriver: new MeilisearchDriver(/* ... */),
));
```

A driver implements seven methods: `list()` and `get()` do the actual read work; `supportedOperators()`, `supportsFilterOn(int $fieldId)`, and `supportsSortOn(int $fieldId)` (the one breaking addition to this interface in the v0.3.0 build) declare per-request and per-field capability; `supportsFuzzySearch()` and `consistencyModel(): 'strong' | 'eventual'` are static self-description the pre-flight pipeline and callers can inspect. The pre-flight pipeline rejects unsupported requests — including an unsortable field — before the driver is invoked. Writes always go to MySQL — drivers are read-only.

A driver can also implement `StarDust\Search\BatchPointReadInterface`, an optional capability with one method, `getMany(int $tenantId, array $entryIds)`, that reads several entries in one round trip. `StarDust::getMany()` hands it a non-empty list of integer ids with no repeats and no more than 1000, and expects the entries that exist as a map keyed by id, in the order given, with the answers `get()` gives for each id. That means leaving out an id that is missing, belongs to another tenant or is soft-deleted, and everything in a model that is being deleted. A driver that does not implement the interface still works, because `getMany()` then asks `get()` for each distinct id.

A request's `SortSpec` can carry a `NullPlacement`, which says whether entries with no value for the sort field come first or last. A driver reads it through `SortSpec::effectiveNulls()` and orders to match. The interface has no capability method for it, so a driver that ignores it orders by its own default and raises no error.
