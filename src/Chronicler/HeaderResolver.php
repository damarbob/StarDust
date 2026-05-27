<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use PDO;

/**
 * Resolves the field-name list for a model's CSV header (also reused
 * for deterministic JSON-key ordering if a future variant wants it).
 *
 * Reads `stardust_fields` once per job claim and returns names sorted
 * lexicographically so the artifact column order is stable across
 * re-claims and worker restarts. Tenancy is enforced via the JOIN
 * against `stardust_models` — `stardust_fields` itself carries no
 * `tenant_id` column (tenancy is inherited through the model). The
 * JOIN doubles as a fail-safe against a caller passing a
 * `(tenantId, modelId)` mismatch: such a query returns zero rows
 * rather than silently leaking another tenant's schema.
 */
final class HeaderResolver
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<string> */
    public function resolve(int $tenantId, int $modelId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.name FROM stardust_fields f'
            . ' INNER JOIN stardust_models m ON m.id = f.model_id'
            . ' WHERE f.model_id = ? AND m.tenant_id = ?'
            . ' ORDER BY f.name ASC'
        );
        $stmt->execute([$modelId, $tenantId]);
        return array_map(
            static fn ($v) => (string) $v,
            $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
        );
    }
}
