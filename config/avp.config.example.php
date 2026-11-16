<?php

/**
 * API Version Platform configuration (optional: the tool works without this file).
 *
 * Created by `vendor/bin/avp init`. Paths are relative to this file.
 * Environment overrides: AVP_README_PATH, AVP_STORAGE_PATH, AVP_HOST, AVP_PORT and AVP_CLOUD_URL.
 */

return [
    'paths' => [
        // The Markdown file that documents your API. null = auto-detect the first of
        // README.md, API.md, docs/API.md, docs/README.md that contains endpoints.
        'readme' => null,
        // Local state folder (versions, request history) as plain JSON files. It is created with a .gitignore, so it stays out of git.
        'storage' => '.avp',
    ],
    'cloud' => [
        // The hosted platform that `Publish to cloud` in the UI signs in to.
        'url' => 'https://qa.erpsite.org.pk/test_avp/',
    ],
    'server' => [
        'host' => '127.0.0.1',
        // Uncomment to pin the port. Left unset, `avp serve` uses 8080, or the next free port if 8080 is busy.
        // 'port' => 8080,
    ],
];
