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

        $record = [
            'ts'    => $ts,
            'level' => (string) $level,
            'event' => $event !== null ? (string) $event : $this->interpolate($message, $context),
        ];

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
            return [
                'class'   => $value::class,
                'message' => $value->getMessage(),
                'file'    => $value->getFile(),
                'line'    => $value->getLine(),
            ];
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }
        return $value;
    }
}
