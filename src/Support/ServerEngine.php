<?php

declare(strict_types=1);

namespace StarDust\Support;

/**
 * The two database dialects StarDust ships DDL for (ADR 0055).
 *
 * A closed enum, not an open string: `Dialect`'s methods take one of
 * these rather than a version string, so a caller cannot construct an
 * engine the switch does not know about. Percona is `MYSQL`, not a
 * third case — it reports a MySQL version string and needs
 * byte-identical DDL (ADR 0023).
 *
 * Detected via {@see ServerEngineDetector::detect()}, never declared —
 * there is deliberately no `Config` field for this. See ADR 0055 §7 for
 * why an override is a decision, not an oversight.
 */
enum ServerEngine
{
    case MYSQL;
    case MARIADB;
}
