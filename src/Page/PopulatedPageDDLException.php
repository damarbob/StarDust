<?php

declare(strict_types=1);

namespace StarDust\Page;

use RuntimeException;

/**
 * Raised by the engine when DDL is attempted against an extension page that
 * already contains rows. Per ADR 0012, populated `entry_slots_page_X` tables
 * are immutable — index and column changes are achieved by provisioning a
 * new empty page, never by mutating an existing one.
 *
 * The guard fires before MySQL would acquire any metadata lock, so the
 * exception surfaces at the engine boundary rather than as a downstream
 * lock-wait timeout.
 */
final class PopulatedPageDDLException extends RuntimeException
{
}
