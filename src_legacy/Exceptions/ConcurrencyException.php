<?php

namespace StarDust\Exceptions;

use CodeIgniter\Exceptions\HTTPExceptionInterface;

class ConcurrencyException extends \RuntimeException implements HTTPExceptionInterface
{
    /**
     * HTTP Status Code
     */
    protected $code = 409; // Conflict

    public static function forModelLostUpdate(int $modelId, ?int $clientVersion, int $serverVersion)
    {
        return new static(lang('StarDust.concurrencyModelLostUpdate', [
            'id' => $modelId,
            'client' => $clientVersion ?? 'null',
            'server' => $serverVersion
        ]));
    }

    public static function forEntryLostUpdate(int $entryId, ?int $clientVersion, int $serverVersion)
    {
        return new static(lang('StarDust.concurrencyEntryLostUpdate', [
            'id' => $entryId,
            'client' => $clientVersion ?? 'null',
            'server' => $serverVersion
        ]));
    }
}
