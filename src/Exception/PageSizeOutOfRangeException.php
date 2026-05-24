<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Phase 4 pre-flight rejection: the `pageSize` requested on an
 * {@see \StarDust\Read\EntryQuery} is outside the engine's bounded
 * range. ADR 0005 makes "every query touches at most a fixed, known
 * number of rows" a load-bearing invariant; an unbounded
 * page size — even one larger than the hard cap — would violate it.
 */
final class PageSizeOutOfRangeException extends RuntimeException
{
}
