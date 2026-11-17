<?php

/**
 * Smoke test for the parser on the oldest PHP the hosted web backend runs (7.4), and on every newer one.
 * Written in PHP 7.4 syntax on purpose: if this file or the parser needs anything newer, the CI job on 7.4 fails.
 *
 * Run: `php tests/hosted_smoke_test.php`
 */

// On PHP 7.4 (the hosted backend) the PHP 8 string functions come from the polyfills the hosted sync installs; the Composer
// package itself needs PHP 8.1 and has no use for them, so this file lives with the sync tooling (absent in an installed copy).
$polyfills = dirname(__DIR__) . '/scripts/hosted/polyfills.php';
if (is_file($polyfills)) {
    require_once $polyfills;
}
require_once dirname(__DIR__) . '/src/autoload.php';

use Mohammad\ApiVersionPlatform\MarkdownOpenApiParser;
use Mohammad\ApiVersionPlatform\Parser\Migrator;
use Mohammad\ApiVersionPlatform\Parser\Validator;

$failures = 0;
$checks = 0;
function smoke($ok, $message)
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

smoke(version_compare(PHP_VERSION, '7.4.0', '>='), 'runs on PHP 7.4 or newer (this is ' . PHP_VERSION . ')');

$template = file_get_contents(dirname(__DIR__) . '/resources/templates/api-readme.md');
$result = MarkdownOpenApiParser::parse($template);
smoke($result['statistics']['endpoints'] === 5, 'the bundled template has 5 endpoints');
smoke($result['errors'] === [] && $result['warnings'] === [], 'the bundled template parses without warnings');
smoke($result['openapi']['openapi'] === '3.0.3', 'the result is an OpenAPI 3.0.3 document');
$paths = (array) $result['openapi']['paths'];
$login = isset($paths['/auth/login.php']) ? (array) $paths['/auth/login.php'] : [];
smoke(isset($login['post']), 'the login operation is present');

$legacy = "# Old API\n\n## Users \xE2\x80\x94 `users/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `list.php` | List users |\n";
$old = MarkdownOpenApiParser::parse($legacy);
smoke($old['statistics']['endpoints'] === 1, 'an old-style document still parses');
$codes = array_column($old['warnings'], 'code');
smoke(in_array('LEGACY_FORMAT', $codes, true), 'an old-style document gets the LEGACY_FORMAT notice');

$strictBad = "---\navp: 1\ntitle: X\nbogus: 1\n---\n\n" . substr($legacy, strpos($legacy, '## Users'));
$bad = MarkdownOpenApiParser::parse($strictBad);
smoke($bad['errors'] === [] && in_array('FRONT_MATTER_UNKNOWN_KEY', array_column($bad['warnings'], 'code'), true), 'strict-mode problems come back as warnings and never block');
$check = Validator::check($strictBad);
smoke($check['strict'] === true && $check['ok'] === false && count($check['errors']) === 1, 'Validator lists the strict problem as an error');

$migrated = Migrator::migrate($legacy);
smoke(strpos($migrated, "---\navp: 1\n") === 0 && Migrator::migrate($migrated) === $migrated, 'Migrator adds the header and is idempotent');

$crlf = MarkdownOpenApiParser::parse(str_replace("\n", "\r\n", $template));
smoke($crlf['statistics']['endpoints'] === 5, 'CRLF line endings parse the same');
$cp1252 = MarkdownOpenApiParser::parse("# T\n\n## U \xE2\x80\x94 `u/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `a.php` | caf\xE9 |\n");
smoke($cp1252['statistics']['endpoints'] === 1 && in_array('NOT_UTF8', array_column($cp1252['warnings'], 'code'), true), 'a non-UTF-8 document is read and flagged');
smoke(json_encode($cp1252['openapi']) !== false, 'the result of a non-UTF-8 document is valid JSON');

// The polyfills (used on PHP 7.4) behave like the native functions.
smoke(str_contains('abc', 'b') && str_contains('abc', '') && !str_contains('abc', 'x'), 'str_contains');
smoke(str_starts_with('abc', 'ab') && str_starts_with('abc', '') && !str_starts_with('abc', 'bc') && !str_starts_with('a', 'abc'), 'str_starts_with');
smoke(str_ends_with('abc', 'bc') && str_ends_with('abc', '') && str_ends_with('', '') && !str_ends_with('a', 'abc') && !str_ends_with('abc', 'ab'), 'str_ends_with');

echo $failures === 0 ? "PASS: {$checks} checks, 0 failures\n" : "FAILED: {$failures} of {$checks} checks\n";
exit($failures === 0 ? 0 : 1);
