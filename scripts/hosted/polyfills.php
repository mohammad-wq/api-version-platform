<?php

declare(strict_types=1);

// PHP 8.0 string functions for the HOSTED web backend, which runs PHP 7.4. Not part of the Composer package (the package
// needs PHP 8.1, where these exist): scripts/sync-hosted.php copies this file next to the hosted copy of the parser.
// Only defined when missing, so on PHP 8+ the native functions are used and this file does nothing.

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        $length = strlen($needle);
        return $length === 0 || ($length <= strlen($haystack) && substr($haystack, -$length) === $needle);
    }
}
