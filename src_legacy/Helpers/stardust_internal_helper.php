<?php

if (!function_exists('locate_query_file')) {
    /**
     * Locates a SQL query file within the StarDust\Queries namespace.
     *
     * @param string $file The name of the SQL file (without extension).
     *
     * @return string The absolute path to the located SQL file.
     *
     * @throws \Exception If the file cannot be found.
     */
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
