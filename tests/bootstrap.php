<?php

/**
 * CodeIgniter 4 Test Bootstrap
 *
 * This file bootstraps the test environment for the StarDust library.
 */

// Define the path to the project root
define('ROOTPATH', realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR);

// Load CodeIgniter's test bootstrap
require ROOTPATH . 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
