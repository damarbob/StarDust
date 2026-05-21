<?php

declare(strict_types=1);

namespace StarDust\Logging;

use DateTimeZone;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Default structured logger mandated by ADR 0020: writes one NDJSON
 * record per call to stdout. Stderr is reserved for PHP fatals.
 *
 * Callers may inject any other PSR-3 logger via Config; doing so transfers
 * ADR 0020 conformance responsibility to that implementation.
 */
final class StdoutNdjsonLogger extends AbstractLogger
{
    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream Defaults to STDOUT. Injectable for tests.
     */
    public function __construct(
        private readonly ClockInterface $clock,
        $stream = null,
    ) {
        $this->stream = $stream ?? STDOUT;
    }

    /**
     * @param mixed              $level
     * @param string|Stringable  $message
     * @param array<string,mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $ts = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.uP');

        $event = $context['event'] ?? null;
        unset($context['event']);

        // ADR 0020 mandates `correlation_id` as a required non-nullable
        // field on every event. Callers (Phase 4 HTTP middleware, Phase 5
        // daemon loops) override by passing their own id; absence or
        // explicit null falls back to a freshly synthesised v4 UUID so the
        // contract is satisfied either way.
        $correlationId = $context['correlation_id'] ?? null;
        unset($context['correlation_id']);

        // ADR 0020 mandates a closed-vocabulary `event` field — callers that
        // omit `event` fall back to a generic category. The human-readable
        // (interpolated) message is preserved separately under `message`
        // whenever non-empty, so PSR-3 detail is never silently discarded.
        $record = [
            'ts'             => $ts,
            'level'          => (string) $level,
            'event'          => $event !== null ? (string) $event : 'generic_log',
            'correlation_id' => is_string($correlationId) && $correlationId !== ''
                ? $correlationId
                : self::generateUuidV4(),
        ];

        $interpolated = $this->interpolate($message, $context);
        if ($interpolated !== '') {
            $record['message'] = $interpolated;
        }

        foreach ($context as $key => $value) {
            if (array_key_exists($key, $record)) {
                continue;
            }
            $record[$key] = $this->normalise($value);
        }

        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = json_encode([
                'ts'    => $ts,
                'level' => 'error',
                'event' => 'log_encode_failed',
                'detail' => json_last_error_msg(),
            ]);
        }
        fwrite($this->stream, $json . "\n");
    }

    /**
     * @param array<string,mixed> $context
     */
    private function interpolate(string|Stringable $message, array $context): string
    {
        $message = (string) $message;
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }
        return $replacements === [] ? $message : strtr($message, $replacements);
    }

    private function normalise(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if ($value instanceof \Throwable) {
            return $this->normaliseThrowable($value);
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }
        return $value;
    }

    /**
     * Serialises a Throwable and walks `getPrevious()` so wrapped exceptions
     * keep their root cause in the NDJSON record (ADR 0020 positions this
     * stream as the primary post-mortem surface). The `previous` key is
     * omitted entirely on the innermost cause to keep single-exception logs
     * noise-free. Depth is capped to guard against pathological self-cycles.
     *
     * @return array<string,mixed>
     */
    private function normaliseThrowable(\Throwable $t, int $depth = 0): array
    {
        $out = [
            'class'   => $t::class,
            'message' => $t->getMessage(),
            'file'    => $t->getFile(),
            'line'    => $t->getLine(),
        ];
        $previous = $t->getPrevious();
        if ($previous !== null && $depth < 8) {
            $out['previous'] = $this->normaliseThrowable($previous, $depth + 1);
        }
        return $out;
    }

    /**
     * RFC 4122 v4 UUID built from 16 cryptographically random bytes. Inlined
     * here because this logger is the sole call site today; promote to a
     * helper if a second caller appears (e.g. Phase 4 request middleware).
     */
    private static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
