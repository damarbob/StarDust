<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when the synchronous bulk-ingest entry point is handed more
 * entities than the ADR 0011 inline threshold (1 000).
 *
 * ADR 0011: "Calling the synchronous bulk-ingest function with a > 1 000-
 * entity batch throws a `payload_too_large` exception carrying a pointer
 * to the async submission path." The threshold is intentionally not
 * configurable — the shape of the contract is load-bearing.
 */
final class PayloadTooLargeException extends RuntimeException
{
}
