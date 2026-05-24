<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown by the `reconciler:dlq:replay` operator command when the
 * `--id` or `--reason` filter matches zero rows in
 * `stardust_reconciler_dlq`. Surfaces as CLI exit code `1`.
 */
final class DlqReplayNotFoundException extends RuntimeException
{
}
