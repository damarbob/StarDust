<?php

namespace StarDust\Config;

use StarDust\Jobs\SyncIndexerJob;

class Registrar
{
    /**
     * Registers the SyncIndexerJob with the Queue configuration.
     *
     * @return array
     */
    public static function Queue(): array
    {
        return [
            'jobHandlers' => [
                'StarDust\Jobs\SyncIndexerJob' => SyncIndexerJob::class,
            ],
        ];
    }
}
