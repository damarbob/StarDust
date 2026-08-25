<?php

declare(strict_types=1);

namespace StarDust\Support;

/**
 * Builds a `LIKE` prefix pattern with the wildcard characters in the
 * prefix escaped.
 *
 * ## Why this exists
 *
 * Four `backfill_checkpoints` job-name namespaces — `retype_field_`,
 * `rename_field_`, `delete_field_` and `delete_model_` — are claimed by
 * `LIKE '<prefix>%'`. Every one of them contains underscores, and in SQL
 * `_` is a **single-character wildcard**, so `'retype_field_%'` also
 * matches `retypeXfieldY...`.
 *
 * Between the engine's own namespaces that is harmless: they are all
 * thirteen characters and their literal segments diverge (`delete_model_`
 * vs `delete_field_` differ at position 8), so no engine job can be
 * claimed by the wrong work source.
 *
 * The hazard is that `job_name` is **operator-supplied** for Backfill
 * Pump CLI jobs, and `schema_reference` §5.4's own worked example is
 * `model_42_rebuild`. An operator job whose name happens to fit the
 * wildcard shape becomes claimable. For the retype and rename namespaces
 * the worst case was a spurious payload rewrite; for ADR 0038's model
 * purge it is an unrecoverable `DELETE FROM entry_data`.
 *
 * Escaping is behaviour-preserving for every engine-generated name — the
 * only rows whose visibility changes are operator-named jobs that were
 * being matched by accident. Each claim query additionally carries its
 * own integrity predicate (`previous_name IS NOT NULL`,
 * `deleted_at IS NOT NULL`), which no operator-named job can satisfy;
 * this is the second key on that door, and the one that keeps working if
 * the joins are ever refactored.
 *
 * Centralised here so a fifth namespace inherits it rather than
 * re-arguing it. `src/Support/` emits no events, so it needs no
 * `EventVocabularyTest` scan.
 */
final class LikePattern
{
    /**
     * The escape character the matching SQL must declare.
     *
     * Bind the pattern from {@see self::escapedPrefix()} and write the
     * clause as `LIKE ? ESCAPE '\\'` in a PHP double-quoted string — which
     * emits a single backslash to MySQL. That is the form already used in
     * `SqlFilterCompiler`; copy it rather than re-deriving the quoting.
     */
    public const ESCAPE_CHAR = '\\';

    /**
     * `escapedPrefix('delete_model_')` → `delete\_model\_%`
     *
     * Escapes `\`, `_` and `%` inside the prefix, then appends an
     * unescaped trailing `%` as the actual wildcard.
     */
    public static function escapedPrefix(string $prefix): string
    {
        return str_replace(
            ['\\', '_', '%'],
            ['\\\\', '\\_', '\\%'],
            $prefix,
        ) . '%';
    }
}
