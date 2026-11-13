<?php

declare(strict_types=1);

use Mohammad\ApiVersionPlatform\Config;
use Mohammad\ApiVersionPlatform\LocalApi;
use Mohammad\ApiVersionPlatform\Storage;
use Mohammad\ApiVersionPlatform\VersionService;

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/SourceHasher.php';
require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/VersionNumber.php';
require_once __DIR__ . '/VersionService.php';
require_once __DIR__ . '/LocalApi.php';

// Errors go to the server log, never into a response body where they would break the JSON the UI reads.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$uiDir = realpath(__DIR__ . '/../resources/ui');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

/** @return array{0: VersionService, 1: Storage, 2: string, 3: string} service, storage, project root, README path */
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
    return [new VersionService($paths['readme'], $storage), $storage, $root, $paths['readme']];
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
        [$service, $storage, $root, $readme] = avpServices();
        (new LocalApi($service, $storage, $root, $readme))->handle(substr($path, 4), $_SERVER['REQUEST_METHOD']);
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

$index = $uiDir === false ? null : $uiDir . DIRECTORY_SEPARATOR . 'index.html';
if ($index === null || !is_file($index)) {
    http_response_code(503);
    echo 'UI bundle missing.';
    return true;
}
header('Content-Type: text/html; charset=utf-8');
readfile($index);
return true;
