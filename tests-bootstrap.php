<?php

require_once __DIR__ . '/vendor/autoload.php';

// Suppress deprecation warnings from vendor code only
set_error_handler(function ($errno, $_errstr, $errfile, $_errline) {
    // If it's a deprecation and it's from vendor directory, suppress it
    if ($errno === E_DEPRECATED && str_contains($errfile, '/vendor/')) {
        return true;
    }

    // Otherwise, let PHPUnit handle it
    return false;
}, E_DEPRECATED);
