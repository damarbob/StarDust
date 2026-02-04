<?php

namespace StarDust\Exceptions;

use CodeIgniter\Exceptions\HTTPExceptionInterface;

class ConcurrencyException extends \RuntimeException implements HTTPExceptionInterface
{
    /**
     * HTTP Status Code
     */
    protected $code = 409; // Conflict

    public static function forLostUpdate(int $modelId, ?int $clientVersion, int $serverVersion)
    {
        return new static(lang('StarDust.concurrencyLostUpdate', [
            'id' => $modelId,
            'client' => $clientVersion ?? 'null',
            'server' => $serverVersion
        ]));
    }
}
