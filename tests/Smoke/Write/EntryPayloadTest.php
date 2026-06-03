<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Write;

use PHPUnit\Framework\TestCase;
use StarDust\Exception\MalformedEntryPayloadException;
use StarDust\Write\EntryPayload;

/**
 * Pure unit tests for the convergent `EntryPayload` factories
 * (`fromArray` / `fromJson` / `listFromArray` / `listFromJson`). These
 * cover envelope-shape validation only — type coercion and the
 * `tenant_id >= 1` rule live on the write path, not the factory — so no
 * database is touched and the class runs on a bare clone.
 */
final class EntryPayloadTest extends TestCase
{
    /**
     * @param callable():mixed $fn
     */
    private function assertRejects(?string $expectedKey, callable $fn): MalformedEntryPayloadException
    {
        try {
            $fn();
            self::fail('expected MalformedEntryPayloadException');
        } catch (MalformedEntryPayloadException $e) {
            self::assertSame(
                $expectedKey,
                $e->key,
                "wrong key; got '" . ($e->key ?? 'null') . "' (message={$e->getMessage()})",
            );
            return $e;
        }
    }

    // ---- happy paths -------------------------------------------------

    public function testFromArrayBuildsPayload(): void
    {
        $payload = EntryPayload::fromArray([
            'tenantId' => 1,
            'modelId'  => 42,
            'fields'   => ['name' => 'Acme Corp', 'employees' => 340],
        ]);

        self::assertSame(1, $payload->tenantId);
        self::assertSame(42, $payload->modelId);
        self::assertSame(['name' => 'Acme Corp', 'employees' => 340], $payload->fields);
    }

    public function testFromJsonBuildsPayload(): void
    {
        $payload = EntryPayload::fromJson(
            '{"tenantId": 1, "modelId": 42, "fields": {"name": "Acme Corp", "employees": 340}}'
        );

        self::assertSame(1, $payload->tenantId);
        self::assertSame(42, $payload->modelId);
        self::assertSame(['name' => 'Acme Corp', 'employees' => 340], $payload->fields);
    }

    public function testFromJsonPreservesNestedFieldValues(): void
    {
        $payload = EntryPayload::fromJson(
            '{"tenantId": 1, "modelId": 7, "fields": {"tags": ["a", "b"], "meta": {"k": 1}}}'
        );

        self::assertSame(['tags' => ['a', 'b'], 'meta' => ['k' => 1]], $payload->fields);
    }

    public function testListFromJsonBuildsList(): void
    {
        $payloads = EntryPayload::listFromJson(
            '[{"tenantId": 1, "modelId": 7, "fields": {"name": "Acme"}},'
            . ' {"tenantId": 1, "modelId": 7, "fields": {"name": "Globex"}}]'
        );

        self::assertCount(2, $payloads);
        self::assertSame('Acme', $payloads[0]->fields['name']);
        self::assertSame('Globex', $payloads[1]->fields['name']);
        self::assertSame(7, $payloads[1]->modelId);
    }

    public function testListFromArrayBuildsList(): void
    {
        $payloads = EntryPayload::listFromArray([
            ['tenantId' => 1, 'modelId' => 7, 'fields' => ['name' => 'Acme']],
        ]);

        self::assertCount(1, $payloads);
        self::assertInstanceOf(EntryPayload::class, $payloads[0]);
    }

    public function testEmptyFieldsAccepted(): void
    {
        $payload = EntryPayload::fromJson('{"tenantId": 1, "modelId": 7, "fields": {}}');
        self::assertSame([], $payload->fields);
    }

    public function testUnknownTopLevelKeysIgnored(): void
    {
        $payload = EntryPayload::fromArray([
            'tenantId' => 1,
            'modelId'  => 7,
            'fields'   => ['name' => 'Acme'],
            'extra'    => 'ignored',
        ]);

        self::assertSame(['name' => 'Acme'], $payload->fields);
    }

    /**
     * Parity with the typed constructor: the factory enforces shape
     * only. The `tenant_id >= 1` rule is the write boundary's job
     * (TenantId::assertValid), so a structurally-valid envelope with
     * tenantId 0 builds without error and fails later, at write().
     */
    public function testTenantIdZeroSucceedsStructurally(): void
    {
        $payload = EntryPayload::fromArray(['tenantId' => 0, 'modelId' => 7, 'fields' => []]);
        self::assertSame(0, $payload->tenantId);
    }

    // ---- envelope-shape rejections -----------------------------------

    public function testRejectsMissingTenantId(): void
    {
        $this->assertRejects('tenantId', static fn () => EntryPayload::fromArray([
            'modelId' => 7,
            'fields'  => [],
        ]));
    }

    public function testRejectsNonIntTenantId(): void
    {
        $this->assertRejects('tenantId', static fn () => EntryPayload::fromArray([
            'tenantId' => '1',
            'modelId'  => 7,
            'fields'   => [],
        ]));
    }

    public function testRejectsMissingModelId(): void
    {
        $this->assertRejects('modelId', static fn () => EntryPayload::fromArray([
            'tenantId' => 1,
            'fields'   => [],
        ]));
    }

    public function testRejectsNonIntModelId(): void
    {
        $this->assertRejects('modelId', static fn () => EntryPayload::fromArray([
            'tenantId' => 1,
            'modelId'  => 7.5,
            'fields'   => [],
        ]));
    }

    public function testRejectsMissingFields(): void
    {
        $this->assertRejects('fields', static fn () => EntryPayload::fromArray([
            'tenantId' => 1,
            'modelId'  => 7,
        ]));
    }

    public function testRejectsNonArrayFields(): void
    {
        $this->assertRejects('fields', static fn () => EntryPayload::fromArray([
            'tenantId' => 1,
            'modelId'  => 7,
            'fields'   => 'not-a-map',
        ]));
    }

    public function testRejectsListShapedFields(): void
    {
        $this->assertRejects('fields', static fn () => EntryPayload::fromJson(
            '{"tenantId": 1, "modelId": 7, "fields": ["a", "b"]}'
        ));
    }

    public function testRejectsInvalidJson(): void
    {
        $this->assertRejects(null, static fn () => EntryPayload::fromJson('{not json'));
    }

    public function testRejectsNonObjectJsonRoot(): void
    {
        $this->assertRejects(null, static fn () => EntryPayload::fromJson('[]'));
        $this->assertRejects(null, static fn () => EntryPayload::fromJson('5'));
        $this->assertRejects(null, static fn () => EntryPayload::fromJson('"x"'));
    }

    public function testRejectsNonArrayListRoot(): void
    {
        $this->assertRejects(null, static fn () => EntryPayload::listFromJson('{}'));
    }

    public function testRejectsNonObjectListElement(): void
    {
        $this->assertRejects('[1]', static fn () => EntryPayload::listFromArray([
            ['tenantId' => 1, 'modelId' => 7, 'fields' => []],
            'not-an-object',
        ]));
    }

    public function testListElementErrorSurfacesIndexQualifiedKey(): void
    {
        $this->assertRejects('[1].modelId', static fn () => EntryPayload::listFromJson(
            '[{"tenantId": 1, "modelId": 7, "fields": {}},'
            . ' {"tenantId": 1, "fields": {}}]'
        ));
    }
}
