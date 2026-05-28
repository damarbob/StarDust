<?php

declare(strict_types=1);

namespace StarDust\Filter\Json;

/**
 * Mutable counter object threaded through a recursive decode pass so
 * the running node total can be tested against the limits at every
 * step without per-call return-value plumbing.
 *
 * Lives strictly inside one call to {@see JsonFilterDecoder::decode()}
 * — never shared across decode calls.
 */
final class DecodeContext
{
    public int $nodeCount = 0;
}
