<?php

if (!function_exists('locate_query_file')) {
    function locate_query_file($file)
    {
        // Call the CI4 Locator Service
        /**
         * @var FileLocator
         */
        $locator = service('locator');

        // Locate the file using the Namespace package
        $filepath = $locator->locateFile('StarDust\\Queries\\' . $file, ext: 'sql');

        // Check if the locator found the path (it returns an empty string if not found)
        if (empty($filepath)) {
            throw new \Exception("SQL file not found in StarDust package: Queries\\$file.sql");
        }

        return $filepath;
    }
}
