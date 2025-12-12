<?php

if (!function_exists('syntax_processor')) {
    /**
     * Returns a new instance of the SyntaxProcessor library.
     *
     * Usage:
     *     $processor = syntax_processor();
     *     $result = $processor->process($content);
     *
     * @return \StarDust\Libraries\SyntaxProcessor
     */
    function syntax_processor()
    {
        return new \StarDust\Libraries\SyntaxProcessor();
    }
}
