<?php

namespace Tests\Unit\Database;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockConnection;
use StarDust\Database\EntriesBuilder;

class LegacyAliasTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new MockConnection([]);
    }

    public function testEntriesBuilderAlias()
    {
        $config = new \StarDust\Config\StarDust();
        $builder = new EntriesBuilder('entries', $this->db, ['config' => $config]);
        $builder->withLegacyAliases(true);

        // Test where with string key
        $builder->where('id', 1);
        $sql = $builder->getCompiledSelect();

        $this->assertStringContainsString('WHERE "entries"."id" = 1', $sql);
    }

    public function testEntriesBuilderAliasWithArray()
    {
        $config = new \StarDust\Config\StarDust();
        $builder = new EntriesBuilder('entries', $this->db, ['config' => $config]);
        $builder->withLegacyAliases(true);

        // Test where with array
        $builder->where([
            'id' => 1,
            'model_id' => 2
        ]);
        $sql = $builder->getCompiledSelect();

        $this->assertStringContainsString('"entries"."id" = 1', $sql);
        $this->assertStringContainsString('"model_data"."model_id" = 2', $sql);
    }

    public function testEntriesBuilderAliasDisabledByDefault()
    {
        $config = new \StarDust\Config\StarDust();
        $builder = new EntriesBuilder('entries', $this->db, ['config' => $config]);

        // Disabled by default
        $builder->where('id', 1);
        $sql = $builder->getCompiledSelect();

        // Should use raw 'id' without prefix map (though DB driver might quote it)
        // With MockConnection and default escaping, it usually ends up as "id"
        // Key distinction: it is NOT "entries"."id"

        $this->assertStringNotContainsString('"entries"."id"', $sql);
        $this->assertStringContainsString('"id" = 1', $sql);
    }

    public function testEntriesBuilderOtherMethods()
    {
        $config = new \StarDust\Config\StarDust();
        $builder = new EntriesBuilder('entries', $this->db, ['config' => $config]);
        $builder->withLegacyAliases(true);

        $builder->orderBy('date_modified', 'DESC');
        $sql = $builder->getCompiledSelect();

        $this->assertStringContainsString('ORDER BY "entry_data"."created_at" DESC', $sql);
    }

    public function testUnknownAlias()
    {
        $config = new \StarDust\Config\StarDust();
        $builder = new EntriesBuilder('entries', $this->db, ['config' => $config]);
        $builder->withLegacyAliases(true);

        $builder->where('unknown_column', 'value');
        $sql = $builder->getCompiledSelect();

        $this->assertStringContainsString('"unknown_column" = \'value\'', $sql);
    }

    public function testModelsBuilderAlias()
    {
        $config = new \StarDust\Config\StarDust();
        $builder = new \StarDust\Database\ModelsBuilder('models', $this->db, ['config' => $config]);
        $builder->withLegacyAliases(true);

        // 'name' in ModelsModel legacy maps to 'model_data.name'
        $builder->where('name', 'My Model');
        $sql = $builder->getCompiledSelect();

        $this->assertStringContainsString('"model_data"."name" = \'My Model\'', $sql);
    }
}
