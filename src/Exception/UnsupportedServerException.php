<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown by {@see \StarDust\Support\ServerEngineDetector::detect()} when
 * the connected server is neither a MySQL/Percona 8.0.13+ nor a MariaDB
 * 10.11+ server (ADR 0055 §4).
 *
 * Detection fails closed rather than defaulting to MySQL: for MariaDB
 * this is load-bearing, not hygiene. MariaDB 10.6 accepts every DDL
 * construct the engine emits — the generated-column live-slot substitute
 * works there, the collation exists there — so nothing in the DDL itself
 * rejects it. What disqualifies 10.6 is the ADR 0013 JSON-fallback read
 * path comparing case-insensitively in a three-byte charset (ADR 0054
 * §1 finding 5), which only a version gate can catch.
 */
final class UnsupportedServerException extends RuntimeException
{
}
