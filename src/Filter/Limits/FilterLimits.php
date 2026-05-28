<?php

declare(strict_types=1);

namespace StarDust\Filter\Limits;

/**
 * Bounds enforced by the JSON decoder + value-type validator per the
 * QueryFilter wire-format blueprint §4.6.
 *
 * Defaults are normative for an out-of-the-box deployment; operators
 * may tune any of the six values via `Config::__construct`. The DTO
 * is constructed once at engine boot and threaded through both the
 * decoder and the validator — there is no global state.
 */
final class FilterLimits
{
    public const DEFAULT_MAX_DEPTH          = 8;
    public const DEFAULT_MAX_NODES          = 256;
    public const DEFAULT_MAX_ARGS           = 64;
    public const DEFAULT_MAX_IN_ELEMENTS    = 1_024;
    public const DEFAULT_MAX_STRING_LENGTH  = 4_096;
    public const DEFAULT_MAX_PAYLOAD_BYTES  = 65_536; // 64 KiB

    public function __construct(
        public readonly int $maxDepth          = self::DEFAULT_MAX_DEPTH,
        public readonly int $maxNodes          = self::DEFAULT_MAX_NODES,
        public readonly int $maxArgs           = self::DEFAULT_MAX_ARGS,
        public readonly int $maxInElements    = self::DEFAULT_MAX_IN_ELEMENTS,
        public readonly int $maxStringLength  = self::DEFAULT_MAX_STRING_LENGTH,
        public readonly int $maxPayloadBytes  = self::DEFAULT_MAX_PAYLOAD_BYTES,
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }
}
