<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform;

/**
 * Resolves package configuration.
 *
 * Precedence (lowest to highest): built-in defaults, avp.config.php, AVP_* environment variables.
 * No config file is required: with none, the API document is auto-detected in the project root.
 */
final class Config
{
    public const FILE_NAME = 'avp.config.php';

    /** Env var the CLI uses to hand the resolved config file path to the server process. */
    public const FILE_ENV = 'AVP_CONFIG_FILE';

    /** Env var the CLI uses to hand the project root to the server process. */
    public const ROOT_ENV = 'AVP_PROJECT_ROOT';

    /** Checked in this order when paths.readme is null (auto-detect). */
    public const README_CANDIDATES = ['README.md', 'API.md', 'docs/API.md', 'docs/README.md'];

    private const DEFAULTS = [
        'paths' => [
            'readme' => null,
            'storage' => '.avp/storage.sqlite',
        ],
        'server' => [
            'host' => '127.0.0.1',
            'port' => 8080,
        ],
    ];

    private const ENV_MAP = [
        'AVP_README_PATH' => ['paths', 'readme'],
        'AVP_STORAGE_PATH' => ['paths', 'storage'],
        'AVP_HOST' => ['server', 'host'],
        'AVP_PORT' => ['server', 'port'],
    ];

    /**
     * @param string|null $file Explicit config file; falls back to the AVP_CONFIG_FILE env var
     * @return array<string, array<string, mixed>> server.port_explicit tells whether the port was chosen by the user
     */
    public static function load(?string $file = null): array
    {
        $config = self::DEFAULTS;
        $portExplicit = false;

        $file ??= getenv(self::FILE_ENV) ?: null;
        if ($file !== null) {
            if (!is_file($file)) {
                throw new \RuntimeException("Config file not found: {$file}");
            }
            try {
                $fromFile = require $file;
            } catch (\ParseError $e) {
                throw new \RuntimeException("Config file has a PHP syntax error on line {$e->getLine()}: {$file} ({$e->getMessage()})");
            }
            if (!is_array($fromFile)) {
                throw new \RuntimeException("Config file must return an array: {$file}");
            }
            $portExplicit = is_array($fromFile['server'] ?? null) && array_key_exists('port', $fromFile['server']);
            $config = array_replace_recursive($config, $fromFile);
        }

        foreach (self::ENV_MAP as $env => [$section, $key]) {
            $value = getenv($env);
            if ($value !== false && $value !== '') {
                $config[$section][$key] = $value;
                $portExplicit = $portExplicit || $env === 'AVP_PORT';
            }
        }

        $config['server']['port'] = self::port($config['server']['port']) ?? $config['server']['port'];
        $config['server']['port_explicit'] = $portExplicit;

        return $config;
    }

    /** Find avp.config.php in the given project root, if present. */
    public static function locate(string $projectRoot): ?string
    {
        $path = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . self::FILE_NAME;
        return is_file($path) ? $path : null;
    }

    /** @return list<string> Problems that prevent the local tool from working */
    public static function validate(array $config): array
    {
        $errors = [];
        $readme = $config['paths']['readme'] ?? null;
        if ($readme !== null && (!is_string($readme) || trim($readme) === '')) {
            $errors[] = 'paths.readme must be a file path, or null to auto-detect it.';
        }
        $storage = $config['paths']['storage'] ?? null;
        if (!is_string($storage) || trim($storage) === '') {
            $errors[] = 'paths.storage is required.';
        }
        $host = $config['server']['host'] ?? null;
        if (!is_string($host) || trim($host) === '') {
            $errors[] = 'server.host must be a host name or IP address.';
        }
        if (self::port($config['server']['port'] ?? null) === null) {
            $errors[] = 'server.port must be a whole number from 1 to 65535 (got ' . var_export($config['server']['port'] ?? null, true) . ').';
        }
        return $errors;
    }

    /** The value as a TCP port, or null when it is not one. */
    public static function port(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit(trim($value))) $value = (int) trim($value);
        return is_int($value) && $value >= 1 && $value <= 65535 ? $value : null;
    }

    public static function absolute(string $root, string $path): string
    {
        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|[\\\\/])#', $path)) return $path;
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * The README and storage paths, resolved against $root (the config file's folder, or the project root).
     * The CLI and the web server both call this, so they always read the same files.
     *
     * @return array{readme: string, storage: string}
     */
    public static function paths(array $config, string $root, ?string $readmeOverride = null): array
    {
        $readme = $readmeOverride ?? $config['paths']['readme'];
        return [
            'readme' => $readme === null ? self::detectReadme($root) : self::absolute($root, (string) $readme),
            'storage' => self::absolute($root, (string) $config['paths']['storage']),
        ];
    }

    /**
     * The first candidate document that contains API endpoints; otherwise the first one that exists;
     * otherwise README.md (which `avp status` then reports as missing).
     */
    public static function detectReadme(string $root): string
    {
        $existing = null;
        foreach (self::README_CANDIDATES as $candidate) {
            $path = self::absolute($root, str_replace('/', DIRECTORY_SEPARATOR, $candidate));
            if (!is_file($path) || !is_readable($path)) continue;
            $existing ??= $path;
            $markdown = (string) file_get_contents($path);
            // Every endpoint path is written in backticks; skip the parser for documents without any.
            if (!str_contains($markdown, '`')) continue;
            require_once __DIR__ . '/MarkdownOpenApiParser.php';
            if ((\MarkdownOpenApiParser::parse($markdown)['statistics']['endpoints'] ?? 0) > 0) {
                return $path;
            }
        }
        return $existing ?? self::absolute($root, 'README.md');
    }
}
