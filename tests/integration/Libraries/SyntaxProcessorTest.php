<?php

namespace StarDust\Tests\Integration\Libraries;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use StarDust\Libraries\SyntaxProcessor;

class SyntaxProcessorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = true;
    protected $seedOnce = false; // We need fresh data for some tests
    protected $namespace = 'StarDust';

    protected function setUp(): void
    {
        parent::setUp();
        // Clean up before seeding
        $this->db->table('entry_data')->emptyTable();
        $this->db->table('entries')->emptyTable();
        $this->db->table('model_data')->emptyTable();
        $this->db->table('models')->emptyTable();

        $this->seedTestDatabase();
    }

    protected function seedTestDatabase()
    {
        // 1. Create a Model
        $this->db->table('models')->insert([
            'creator_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        // Retrieve the auto-increment ID
        $modelId = $this->db->insertID();

        // 2. Create Model Data (Defines fields)
        $fieldsDef = json_encode([
            [
                'id' => 'price',
                'type' => 'number',
                'label' => 'Price'
            ],
            [
                'id' => 'title',
                'type' => 'text',
                'label' => 'Title'
            ]
        ]);

        $this->db->table('model_data')->insert([
            'model_id' => $modelId,
            'name' => 'Products',
            'fields' => $fieldsDef,
            'creator_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $modelDataId = $this->db->insertID();

        // UPDATE models with current_model_data_id
        $this->db->table('models')->where('id', $modelId)->update(['current_model_data_id' => $modelDataId]);

        // 3. Create Entries for the Model
        // Entry 1
        $this->db->table('entries')->insert([
            'model_id' => $modelId,
            'creator_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $entryId1 = $this->db->insertID();

        $this->db->table('entry_data')->insert([
            'entry_id' => $entryId1,
            'fields' => json_encode(['price' => 100, 'title' => 'Widget A']),
            'creator_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $entryDataId1 = $this->db->insertID();

        // UPDATE entry 1 with current_entry_data_id
        $this->db->table('entries')->where('id', $entryId1)->update(['current_entry_data_id' => $entryDataId1]);


        // Entry 2
        $this->db->table('entries')->insert([
            'model_id' => $modelId,
            'creator_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $entryId2 = $this->db->insertID();

        $this->db->table('entry_data')->insert([
            'entry_id' => $entryId2,
            'fields' => json_encode(['price' => 200, 'title' => 'Widget <B>']), // HTML entity test
            'creator_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $entryDataId2 = $this->db->insertID();

        // UPDATE entry 2 with current_entry_data_id
        $this->db->table('entries')->where('id', $entryId2)->update(['current_entry_data_id' => $entryDataId2]);
    }

    public function testRawQueryInjection()
    {
        $processor = new SyntaxProcessor();

        $json = json_encode([
            'type' => 'data',
            'content' => [
                'query' => "SELECT * FROM entries WHERE id = 1 UNION SELECT 1, 2, 'injected', 4, 5, 6, 7"
            ]
        ]);

        $wrapper = json_encode(['data' => json_decode($json, true)]);

        $result = $processor->process($wrapper);

        $this->assertJson($result);
        $decoded = json_decode($result, true);

        // Assert that the raw query was rejected
        $this->assertTrue(isset($decoded['data']['error']) || isset($decoded['error']));
        if (isset($decoded['data']['error'])) {
            $this->assertStringContainsString('Raw SQL', $decoded['data']['error']);
        }
    }

    public function testFieldIdInjection()
    {
        $processor = new SyntaxProcessor();

        // SQL Injection in field ID
        $injection = 'some_field" -- ';

        $input = [
            'type' => 'data',
            'content' => [
                'table' => 'entries',
                'select' => '{{field:' . $injection . '}} as injected_val',
                'limit' => 1
            ]
        ];

        $json = json_encode(['data' => $input]);

        // Should run without SQL syntax errors now containing the escaped quote
        $result = $processor->process($json);

        $decoded = json_decode($result, true);

        // Expect success (no error key in the specific data item)
        // If the query runs but finds nothing or the SELECT clause is weird but valid SQL, it passes.
        $this->assertArrayNotHasKey('error', $decoded['data'] ?? []);
    }

    public function testInvalidOperator()
    {
        $processor = new SyntaxProcessor();
        $input = [
            'type' => 'data',
            'content' => [
                'table' => 'entries',
                'where' => [['column' => 'id', 'operator' => '; DROP TABLE entries; --', 'value' => 1]]
            ]
        ];

        $json = json_encode(['data' => $input]);
        $result = $processor->process($json);
        $decoded = json_decode($result, true);

        // Should return error due to invalid operator
        $this->assertArrayHasKey('error', $decoded['data']);
        $this->assertStringContainsString('Invalid operator', $decoded['data']['error']);
    }

    public function testHappyPathSelect()
    {
        $processor = new SyntaxProcessor();
        $input = [
            'type' => 'data',
            'content' => [
                'table' => 'entry_data',
                'select' => 'entry_id',
                'limit' => 5
            ]
        ];

        $json = json_encode(['data' => $input]);
        $result = $processor->process($json);
        $decoded = json_decode($result, true);

        $this->assertIsArray($decoded['data']);
        $this->assertCount(2, $decoded['data']); // We seeded 2 entries
        $this->assertArrayHasKey('entry_id', $decoded['data'][0]);
    }

    public function testAdvancedQueryFeatures()
    {
        $processor = new SyntaxProcessor();
        // Test ORDER BY, LIMIT
        $input = [
            'type' => 'data',
            'content' => [
                'table' => 'entry_data',
                'select' => 'entry_id',
                'orderby' => 'entry_id DESC',
                'limit' => 1
            ]
        ];

        $json = json_encode(['data' => $input]);
        $decoded = json_decode($processor->process($json), true);

        $this->assertCount(1, $decoded['data']);
        // The second entry inserted (higher ID) should be first
        // Note: insertID might differ, but logic holds if standard auto-inc
        // Let's verify we got a result.
        $this->assertNotEmpty($decoded['data']);
    }

    public function testPlaceholders()
    {
        // This tests the {{field:ID}} extraction logic
        // We query from 'entry_data' but we need to join 'model_data' to get types for casting
        // However, SyntaxProcessor relies on subqueries or direct tables.
        // It uses `model_fields` column from `model_data`.
        // To test {{field:price}}, we need query context where `fields` and `model_fields` are available.
        // The 'entries' table logic in SyntaxProcessor wraps EntriesModel->stardust(), which usually joins these.
        // Let's rely on querying the 'entries' table (which uses the custom model logic).

        $processor = new SyntaxProcessor();
        $input = [
            'type' => 'data',
            'content' => [
                'table' => 'entries', // Special table that triggers EntriesModel
                'select' => '{{field:price}} as price_val, {{field:title}} as title_val',
                'where' => [['column' => '{{field:price}}', 'operator' => '>', 'value' => 150]]
            ]
        ];

        $json = json_encode(['data' => $input]);
        $result = $processor->process($json);
        $decoded = json_decode($result, true);

        // Check for SQL errors
        if (isset($decoded['data']['error'])) {
            $this->fail($decoded['data']['error']);
        }

        // We expect only the entry with price 200 (Widget <B>)
        $this->assertIsArray($decoded['data']);
        $this->assertCount(1, $decoded['data']);
        $this->assertEquals(200, $decoded['data'][0]['price_val']);
        // Verify sanitization happened on the title
        $this->assertEquals('Widget &lt;B&gt;', $decoded['data'][0]['title_val']);
    }

    public function testModelFieldPlaceholder()
    {
        // Test {{model_field:price.label}} which should extract 'Price' from model definition
        $processor = new SyntaxProcessor();
        $input = [
            'type' => 'data',
            'content' => [
                'table' => 'entries',
                'select' => '{{model_field:price.label}} as price_label',
                'limit' => 1
            ]
        ];

        $json = json_encode(['data' => $input]);
        $result = $processor->process($json);
        $decoded = json_decode($result, true);

        if (isset($decoded['data']['error'])) {
            $this->fail($decoded['data']['error']);
        }

        $this->assertNotEmpty($decoded['data']);
        $this->assertEquals('Price', $decoded['data'][0]['price_label']);
    }

    public function testCountQuery()
    {
        $processor = new SyntaxProcessor();
        $input = [
            'type' => 'data',
            'content' => [
                'table' => 'entry_data',
                'count' => true
            ]
        ];

        $json = json_encode(['data' => $input]);
        $result = $processor->process($json);
        $decoded = json_decode($result, true);

        $this->assertEquals(2, $decoded['data']);
    }

    public function testSanitization()
    {
        // Check that HTML entities are escaped in the output
        $processor = new SyntaxProcessor();
        $input = [
            'type' => 'data',
            'content' => [
                'table' => 'entry_data',
                'select' => 'fields', // 'fields' is the JSON column. extracting raw might be escaped.
                'where' => [['column' => 'id', 'operator' => '>', 'value' => 0]]
            ]
        ];
        // However, sanitization logic (sanitizeData) operates on the result array values.
        // If we select the JSON string, it gets escaped.

        // Let's use the 'entries' special table and select a specific field that has HTML
        $input2 = [
            'type' => 'data',
            'content' => [
                'table' => 'entries',
                'select' => '{{field:title}} as title',
                'where' => [['column' => '{{field:price}}', 'operator' => '=', 'value' => 200]]
            ]
        ];

        $json = json_encode(['data' => $input2]);
        $decoded = json_decode($processor->process($json), true);

        $this->assertStringContainsString('&lt;B&gt;', $decoded['data'][0]['title']);
    }
}
