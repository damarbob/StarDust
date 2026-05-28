<?php

declare(strict_types=1);

namespace StarDust\Filter\Json;

/**
 * RFC 6901 JSON Pointer accumulator used by the decoder to report the
 * location of a structural error within the input envelope.
 *
 * The pointer is built by descent — every recursive call appends a
 * segment and the helper handles the `/`, `~`, escape rules per
 * RFC 6901 §3.
 *
 * Instances are immutable; `child()` returns a new pointer rather
 * than mutating the receiver, so the decoder can branch into siblings
 * without unwinding state manually.
 */
final class JsonPointer
{
    /** @param list<string> $segments raw (unescaped) segments */
    private function __construct(
        private readonly array $segments,
    ) {
    }

    public static function root(): self
    {
        return new self([]);
    }

    public function child(string|int $segment): self
    {
        return new self([...$this->segments, (string) $segment]);
    }

    public function toString(): string
    {
        if ($this->segments === []) {
            return '';
        }
        $escaped = array_map(
            // RFC 6901 §3: '~' encoded as '~0', '/' as '~1'.
            static fn (string $s): string => '/' . strtr($s, ['~' => '~0', '/' => '~1']),
            $this->segments,
        );
        return implode('', $escaped);
    }
}
