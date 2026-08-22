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
        return array_values(array_map(
            static fn ($v) => (string) $v,
            $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
        ));
    }

    /**
     * ADR 0036 rename aliases for the model: current name → pre-rename
     * name, for fields whose rename backfill is still draining. Empty
     * in steady state.
     *
     * This exists because the header list and the payload projection key
     * are the same `list<string>` in {@see CsvArtifactStream}, and during
     * a rename window those two roles diverge: the header must say the
     * new name (an operator reads it), while rows behind the backfill
     * cursor are still keyed by the old one. Without the alias the
     * export emits a correct header over blank cells — silently, into an
     * artifact the consumer keeps and never retries.
     *
     * Deliberately a separate method rather than a change to
     * {@see self::resolve()}: that return shape has two consumers.
     *
     * @return array<string, string>
     */
    public function resolveAliases(int $tenantId, int $modelId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.name, f.previous_name FROM stardust_fields f'
            . ' INNER JOIN stardust_models m ON m.id = f.model_id'
            . ' WHERE f.model_id = ? AND m.tenant_id = ?'
            . '   AND f.previous_name IS NOT NULL'
        );
        $stmt->execute([$modelId, $tenantId]);

        $aliases = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $aliases[(string) $row['name']] = (string) $row['previous_name'];
        }
        return $aliases;
    }
}
