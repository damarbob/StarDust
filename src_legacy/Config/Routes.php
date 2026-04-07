<?php

use CodeIgniter\Router\RouteCollection;

$config = config('StarDust');
$path = $config->workerPath ?? 'stardust/worker';

/**
 * @var RouteCollection $routes
 */
$routes->get($path . '/(:any)', '\StarDust\Controllers\QueueWorker::work/$1');
