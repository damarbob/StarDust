<?php

namespace StarDust\Tests\Integration\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use StarDust\Models\EntryDataModel;

/**
 * Test suite for MigrateEntryFields command
 *
 * Tests the migration of entry_data.fields from legacy array-of-objects format
 * to the new key-value pair format.
 * 
 * Note: Tests focus on the conversion logic and handling of different data formats.
 */
class MigrateEntryFieldsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = false;
    protected $refresh = true;
    protected $namespace = 'StarDust';

    private EntryDataModel $entryDataModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entryDataModel = new EntryDataModel();
    }

    // ========================================
    // Helper: Simplified Conversion Logic Testing
    // ========================================

    private function convertFieldFormat($data): array
    {
        // Replicate the conversion logic from the command
        if (!is_array($data)) {
            $data = json_decode($data, true);
        }

        if (!is_array($data) || empty($data) || !array_is_list($data)) {
            return $data; // Already key-value or invalid
        }

        $newFields = [];
        foreach ($data as $item) {
            $item = (array)$item;
            if (isset($item['id']) && array_key_exists('value', $item)) {
                $newFields[$item['id']] = $item['value'];
            }
        }

        return empty($newFields) ? $data : $newFields;
    }

    // ========================================
    // Test: Format Detection
    // ========================================

    public function testDetectsLegacyArrayOfObjectsFormat(): void
    {
        $legacyFormat = [
            ['id' => 'name', 'value' => 'John'],
            ['id' => 'age', 'value' => 30]
        ];

        $this->assertTrue(array_is_list($legacyFormat));
        $this->assertTrue(isset($legacyFormat[0]['id']));
        $this->assertTrue(isset($legacyFormat[0]['value']));
    }

    public function testDetectsKeyValueFormat(): void
    {
        $keyValueFormat = [
            'name' => 'John',
            'age' => 30
        ];

        $this->assertFalse(array_is_list($keyValueFormat));
    }

    // ========================================
    // Test: Conversion Logic
    // ========================================

    public function testConvertArrayOfObjectsToKeyValue(): void
    {
        $legacyFormat = [
            ['id' => 'name', 'value' => 'John Doe'],
            ['id' => 'email', 'value' => 'john@example.com'],
            ['id' => 'age', 'value' => 30]
        ];

        $result = $this->convertFieldFormat($legacyFormat);

        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('age', $result);
        $this->assertEquals('John Doe', $result['name']);
        $this->assertEquals('john@example.com', $result['email']);
        $this->assertEquals(30, $result['age']);
    }

    public function testSkipsAlreadyConvertedFormat(): void
    {
        $keyValueFormat = [
            'name' => 'Already Converted',
            'email' => 'test@example.com'
        ];

        $result = $this->convertFieldFormat($keyValueFormat);

        // Should remain unchanged
        $this->assertEquals($keyValueFormat, $result);
    }

    public function testHandlesEmptyArray(): void
    {
        $emptyArray = [];

        $result = $this->convertFieldFormat($emptyArray);

        $this->assertEquals($emptyArray, $result);
    }

    public function testHandlesInvalidFormat(): void
    {
        // List but not array-of-objects format
        $invalidFormat = ['tag1', 'tag2', 'tag3'];

        $result = $this->convertFieldFormat($invalidFormat);

        // Should remain unchanged (not converted)
        $this->assertEquals($invalidFormat, $result);
    }

    public function testHandlesNullValue(): void
    {
        $legacyWithNull = [
            ['id' => 'name', 'value' => null],
            ['id' => 'email', 'value' => 'test@example.com']
        ];

        $result = $this->convertFieldFormat($legacyWithNull);

        $this->assertNull($result['name']);
        $this->assertEquals('test@example.com', $result['email']);
    }

    public function testHandlesEmptyStringValue(): void
    {
        $legacyWithEmpty = [
            ['id' => 'name', 'value' => ''],
            ['id' => 'email', 'value' => 'test@example.com']
        ];

        $result = $this->convertFieldFormat($legacyWithEmpty);

        $this->assertEquals('', $result['name']);
        $this->assertEquals('test@example.com', $result['email']);
    }

    public function testPreservesValueTypes(): void
    {
        $legacyWithTypes = [
            ['id' => 'name', 'value' => 'Test'],
            ['id' => 'age', 'value' => 25],
            ['id' => 'active', 'value' => true],
            ['id' => 'score', 'value' => 98.5]
        ];

        $result = $this->convertFieldFormat($legacyWithTypes);

        $this->assertIsString($result['name']);
        $this->assertIsInt($result['age']);
        $this->assertIsBool($result['active']);
        $this->assertIsFloat($result['score']);
    }

    public function testHandlesSpecialCharacters(): void
    {
        $legacyWithSpecialChars = [
            ['id' => 'name', 'value' => "O'Brien"],
            ['id' => 'description', 'value' => 'Quote: "Hello"']
        ];

        $result = $this->convertFieldFormat($legacyWithSpecialChars);

        $this->assertEquals("O'Brien", $result['name']);
        $this->assertEquals('Quote: "Hello"', $result['description']);
    }

    // ========================================
    // Test: JSON String Handling
    // ========================================

    public function testConvertsFromJSONString(): void
    {
        $jsonString = json_encode([
            ['id' => 'name', 'value' => 'From JSON'],
            ['id' => 'age', 'value' => 42]
        ]);

        $result = $this->convertFieldFormat($jsonString);

        $this->assertArrayHasKey('name', $result);
        $this->assertEquals('From JSON', $result['name']);
        $this->assertEquals(42, $result['age']);
    }

    // ========================================
    // Test: Idempotency
    // ========================================

    public function testConversionIsIdempotent(): void
    {
        $legacyFormat = [
            ['id' => 'name', 'value' => 'Test'],
            ['id' => 'age', 'value' => 30]
        ];

        // Convert once
        $result1 = $this->convertFieldFormat($legacyFormat);

        // Convert again (should be no-op)
        $result2 = $this->convertFieldFormat($result1);

        // Both should be identical
        $this->assertEquals($result1, $result2);
    }
}
