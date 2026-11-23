<?php

declare(strict_types=1);

use Mohammad\ApiVersionPlatform\Config;
use Mohammad\ApiVersionPlatform\LocalApi;
use Mohammad\ApiVersionPlatform\Storage;
use Mohammad\ApiVersionPlatform\VersionService;

require_once __DIR__ . '/autoload.php';

// Errors go to the server log, never into a response body where they would break the JSON the UI reads.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// The tool has no authentication and is meant for the local machine only: do not let other sites frame or sniff it,
// and do not announce the PHP version.
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');

$uiDir = realpath(__DIR__ . '/../resources/ui');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

// A NUL byte in a path (/%00, /a%00.js) makes realpath() throw: answer it like any other missing file.
if (str_contains($path, "\0")) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    return true;
}

/** @return array{0: VersionService, 1: Storage, 2: string, 3: string, 4: string} service, storage, project root, README path, cloud URL */
function avpServices(): array
{
    $configFile = getenv(Config::FILE_ENV) ?: null;
    $config = Config::load($configFile);
    $errors = Config::validate($config);
    if ($errors) throw new RuntimeException(implode(' ', $errors));
    // Same root as the CLI: the config file's folder, else the project root the CLI passed down.
    $root = $configFile ? dirname($configFile) : (getenv(Config::ROOT_ENV) ?: getcwd());
    $paths = Config::paths($config, $root);
    $storage = new Storage($paths['storage']);
    return [new VersionService($paths['readme'], $storage), $storage, $root, $paths['readme'], (string) $config['cloud']['url']];
}

/** The API is unauthenticated, so only same-machine pages may call it. */
function avpGuard(): void
{
    $loopback = ['127.0.0.1', 'localhost', '::1', '[::1]'];
    $bind = getenv('AVP_SERVER_HOST') ?: '127.0.0.1';
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
    // A non-loopback Host on a loopback-bound server means DNS rebinding.
    if (in_array($bind, $loopback, true) && !in_array($host, $loopback, true)) {
        LocalApi::send(403, 'Requests must use localhost or 127.0.0.1.');
    }
    // A cross-site page cannot send a JSON content type without a CORS preflight, which this server never grants.
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
        LocalApi::send(415, 'Expected a JSON request body.');
    }
}

if (str_starts_with($path, '/api/')) {
    try {
        avpGuard();
        [$service, $storage, $root, $readme, $cloudUrl] = avpServices();
        (new LocalApi($service, $storage, $root, $readme, $cloudUrl))->handle(substr($path, 4), $_SERVER['REQUEST_METHOD']);
        LocalApi::send(404, 'Not Found');
    } catch (InvalidArgumentException $e) {
        LocalApi::send(400, $e->getMessage());
    } catch (RuntimeException $e) {
        LocalApi::send(422, $e->getMessage());
    } catch (Throwable $e) {
        error_log($e->getMessage());
        LocalApi::send(500, 'Local API error.');
    }
}

if ($path !== '/' && $uiDir !== false) {
    $candidate = realpath($uiDir . DIRECTORY_SEPARATOR . ltrim($path, '/'));
    if ($candidate !== false && is_file($candidate) && str_starts_with($candidate, $uiDir . DIRECTORY_SEPARATOR)) return false;
}

// A missing file (script, image, ...) is a 404, not the app page: a 200 HTML answer breaks module loading with a confusing error.
if (preg_match('#\.[A-Za-z0-9]{1,8}$#', $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    return true;
}
// Single-segment unknown paths (/login, /api) load the app, which sends the visitor home. A deeper unknown path
// (/versions/1/compare) would load the page with a wrong asset base and render blank, so it redirects home instead.
if (!preg_match('#^/(projects(/.*)?|[^/]*/?)$#', $path)) {
    header('Location: /', true, 302);
    return true;
}

$index = $uiDir === false ? null : $uiDir . DIRECTORY_SEPARATOR . 'index.html';
if ($index === null || !is_file($index)) {
    http_response_code(503);
    echo 'UI bundle missing.';
    return true;
}
header('Content-Type: text/html; charset=utf-8');
// The page's own script sets <base> at runtime, which is too late for the browser's preload scanner: on a deep link such as
// /projects/1 it has already asked for /projects/assets/... (a 404). A static <base> first in <head> makes it ask for /assets/....
$html = (string) file_get_contents($index);
echo preg_replace('/<head(\s[^>]*)?>/i', '$0<base href="/">', $html, 1);
return true;
