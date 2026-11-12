<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * `StarDust::getMany()` was given more ids than one call may read.
 *
 * The limit is the same 1 000 that bounds a page, so a batch read touches a
 * fixed, known number of rows and ADR 0005's bounded-read invariant holds. It
 * is checked on the list as it was passed, before repeated ids are collapsed,
 * so the check costs nothing however large the list is. Raised before any
 * statement is sent.
 */
final class TooManyEntryIdsException extends RuntimeException
{
}
