<?php

declare(strict_types=1);

/**
 * StarDust quickstart seed script.
 *
 * Run by the `init` service in docker-compose.yml after the schema is
 * bootstrapped. Also a readable, copy-pasteable example of the whole
 * flow: register a model, make its fields filterable, write entries
 * from raw JSON, and run an indexed filter decoded from a JSON wire
 * payload.
 *
 * Idempotent — safe to run on every `docker compose up`. The model and
 * fields are get-or-create; the page/slot/entry seeding is skipped once
 * the model already has entries.
 */

require __DIR__ . '/../vendor/autoload.php';

use StarDust\Config\Config;
use StarDust\Filter\Json\JsonFilterDecoder;
use StarDust\Page\PageProvisioner;
use StarDust\Read\EntryQuery;
use StarDust\Schema\FieldDefinition;
use StarDust\Slot\SlotReserver;
use StarDust\StarDust;
use StarDust\Write\EntryPayload;

$pdo = new PDO(
    (string) getenv('STARDUST_DSN'),
    (string) getenv('STARDUST_USER'),
    getenv('STARDUST_PASS') === false ? '' : (string) getenv('STARDUST_PASS'),
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
);

$engine = new StarDust(new Config(pdo: $pdo));
$engine->bootstrap(); // idempotent

$tenantId = 1;

// 1) Register the model and its fields — no raw SQL required.
$company = $engine->schemaBuilder()->createModel($tenantId, 'company', [
    new FieldDefinition('name',      'string',   isFilterable: true),
    new FieldDefinition('industry',  'string',   isFilterable: true),
    new FieldDefinition('employees', 'int',      isFilterable: true),
    new FieldDefinition('founded',   'datetime', isFilterable: true),
]);
$modelId = $company->modelId;

// 2) Make the filterable fields queryable: provision an indexed page and
//    reserve one slot per field. In a running deployment the Watcher
//    daemon provisions capacity automatically; here we do it inline so
//    the very first query already hits indexed columns.
$alreadySeeded = ((int) $pdo->query(
    "SELECT COUNT(*) FROM entry_data WHERE tenant_id = {$tenantId} AND model_id = {$modelId}"
)->fetchColumn()) > 0;

if (! $alreadySeeded) {
    (new PageProvisioner($pdo, $engine->config()->clock, $engine->logger()))
        ->provision(filterableSlots: ['i_str_01', 'i_str_02', 'i_int_01', 'i_dt_01']);

    $reserver = new SlotReserver($pdo, $engine->config()->clock, $engine->logger());
    $reserver->reserve($company->fieldId('name'));
    $reserver->reserve($company->fieldId('industry'));
    $reserver->reserve($company->fieldId('employees'));
    $reserver->reserve($company->fieldId('founded'));

    // 3) Write a handful of entries — straight from a raw JSON array, the
    //    way a CMS or HTTP layer would hand them over. The envelope is
    //    {tenantId, modelId, fields} (camelCase); values coerce on the
    //    same write path as the typed `new EntryPayload(...)` constructor.
    $companies = <<<JSON
    [
      {"tenantId": {$tenantId}, "modelId": {$modelId}, "fields": {"name": "Acme Corp", "industry": "manufacturing", "employees": 340,  "founded": "1998-04-12T00:00:00+00:00"}},
      {"tenantId": {$tenantId}, "modelId": {$modelId}, "fields": {"name": "Globex",    "industry": "energy",        "employees": 85,   "founded": "2011-09-01T00:00:00+00:00"}},
      {"tenantId": {$tenantId}, "modelId": {$modelId}, "fields": {"name": "Initech",   "industry": "software",      "employees": 510,  "founded": "2003-01-20T00:00:00+00:00"}},
      {"tenantId": {$tenantId}, "modelId": {$modelId}, "fields": {"name": "Umbrella",  "industry": "pharma",        "employees": 1200, "founded": "1990-06-30T00:00:00+00:00"}},
      {"tenantId": {$tenantId}, "modelId": {$modelId}, "fields": {"name": "Hooli",     "industry": "software",      "employees": 240,  "founded": "2009-11-11T00:00:00+00:00"}}
    ]
    JSON;

    foreach (EntryPayload::listFromJson($companies) as $payload) {
        $engine->write($payload);
    }
}

// 4) The payoff: an indexed filter over user-defined fields. The filter
//    arrives as a JSON wire payload — exactly what an HTTP gateway would
//    receive — and decodes into the same AST the typed builder produces.
//    A pure-AND tree compiles to an INNER JOIN range scan on the
//    composite indexes.
//
//    Typed equivalent:
//      new AndNode([
//          LeafNode::local('industry', 'eq', 'software'),
//          LeafNode::local('employees', 'gt', 100),
//      ])
$filter = (new JsonFilterDecoder($engine->config()->queryFilterLimits))->decode(<<<JSON
    {
      "filter": {
        "op": "and",
        "args": [
          {"op": "eq", "field": {"model": "company", "name": "industry"},  "value": "software"},
          {"op": "gt", "field": {"model": "company", "name": "employees"}, "value": 100}
        ]
      }
    }
    JSON);

$page = $engine->read(new EntryQuery(
    tenantId:     $tenantId,
    modelId:      $modelId,
    filter:       $filter,
    selectFields: ['name', 'industry', 'employees'],
    pageSize:     10,
));

echo "\n=== StarDust quickstart ===\n";
echo "Software companies with more than 100 employees:\n";
foreach ($page->rows as $entry) {
    printf(
        "  - %-12s %-14s %d employees\n",
        $entry->fields['name'],
        $entry->fields['industry'],
        $entry->fields['employees'],
    );
}
echo "\nSchema is bootstrapped and seeded. The Watcher, Reconciler, Liberator,\n";
echo "and Chronicler daemons are running in their own containers.\n";
echo "Edit docker/seed.php to experiment, then `docker compose up init` again.\n\n";
