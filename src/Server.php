<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform;

/** Launches PHP's built-in server with the package router and bundled UI. */
final class Server
{
    /** How many ports after the default one `serve` tries when the user did not pick a port. */
    private const PORT_ATTEMPTS = 20;

    public static function packageRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Run the server in the foreground; returns its exit code.
     *
     * @param string|null $configFile Passed to the server process via AVP_CONFIG_FILE
     * @param string $projectRoot Passed via AVP_PROJECT_ROOT, so the server resolves paths exactly like the CLI
     * @param bool $findFreePort Move on to the next port when $port is taken (the user did not choose it)
     */
    public static function run(string $host, int $port, ?string $configFile, string $projectRoot, bool $findFreePort = false, bool $open = false): int
    {
        $attempts = $findFreePort ? self::PORT_ATTEMPTS : 1;
        $problem = null;
        for ($i = 0; $i < $attempts && $port + $i <= 65535; $i++) {
            $problem = self::portProblem($host, $port + $i);
            if ($problem === null) {
                if ($i > 0) fwrite(STDERR, "Port {$port} is busy, using " . ($port + $i) . " instead.\n");
                $port += $i;
                break;
            }
        }
        if ($problem !== null) {
            fwrite(STDERR, $problem . ($findFreePort ? " (also tried the next " . ($attempts - 1) . " ports)" : '') . " Use --port=PORT to pick another port.\n");
            return 1;
        }

        $root = self::packageRoot();
        $command = [
            PHP_BINARY,
            '-S', "{$host}:{$port}",
            '-t', $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'ui',
            $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'router.php',
        ];

        $env = getenv();
        $env['AVP_SERVER_HOST'] = $host;
        $env[Config::ROOT_ENV] = $projectRoot;
        // Lets the UI keep working while the Test APIs tab waits on a slow API. PHP does not support workers on Windows.
        if (PHP_OS_FAMILY !== 'Windows' && !isset($env['PHP_CLI_SERVER_WORKERS'])) {
            $env['PHP_CLI_SERVER_WORKERS'] = '4';
        }
        unset($env[Config::FILE_ENV]);
        if ($configFile !== null) {
            $env[Config::FILE_ENV] = $configFile;
        }

        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $projectRoot, $env);
        if (!is_resource($process)) {
            fwrite(STDERR, "Failed to start the PHP built-in server.\n");
            return 1;
        }

        // Announce the URL only once the server answers: a port PHP refuses must not be reported as up.
        $deadline = microtime(true) + 10;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                proc_close($process);
                fwrite(STDERR, "The PHP built-in server stopped while starting (exit code {$status['exitcode']}).\n");
                return $status['exitcode'] > 0 ? $status['exitcode'] : 1;
            }
            if (self::reachable($host, $port) || microtime(true) > $deadline) break;
            usleep(50_000);
        }

        $url = 'http://' . (str_contains($host, ':') ? "[{$host}]" : $host) . ":{$port}";
        if ($host === '0.0.0.0' || $host === '::') $url = "http://127.0.0.1:{$port}";
        echo "API Version Platform running at {$url}  (press Ctrl+C to stop)\n";
        if ($open) self::openBrowser($url);

        return proc_close($process);
    }

    /** Why $host:$port cannot be used, or null when it is free. */
    private static function portProblem(string $host, int $port): ?string
    {
        // A TCP connect is the authoritative "in use" check: a bind probe alone can "succeed" on Windows
        // even when another listener owns the port.
        if (self::reachable($host, $port)) {
            return "Port {$port} is already in use on {$host}.";
        }
        $probe = @stream_socket_server('tcp://' . (str_contains($host, ':') ? "[{$host}]" : $host) . ":{$port}", $errNo, $errStr);
        if ($probe === false) {
            return "Could not bind {$host}:{$port}: {$errStr} (port in use, or blocked/excluded on Windows?).";
        }
        fclose($probe);
        return null;
    }

    private static function reachable(string $host, int $port): bool
    {
        $connectHost = match ($host) {
            '0.0.0.0' => '127.0.0.1',
            '::' => '[::1]',
            default => str_contains($host, ':') ? "[{$host}]" : $host,
        };
        $peer = @fsockopen($connectHost, $port, $errNo, $errStr, 0.3);
        if (!is_resource($peer)) return false;
        fclose($peer);
        return true;
    }

    private static function openBrowser(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Windows' => ['cmd', '/c', 'start', '', $url],
            'Darwin' => ['open', $url],
            default => ['xdg-open', $url],
        };
        $process = @proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (is_resource($process)) {
            foreach ($pipes as $pipe) fclose($pipe);
            proc_close($process);
        } else {
            fwrite(STDERR, "Could not open a browser; visit {$url} yourself.\n");
        }
    }

    /**
     * Sets a project up in one step: avp.config.php, the self-ignoring .avp/ state folder and,
     * when the project has no API document yet, API.md from the bundled template.
     *
     * @return array{config: string, state: string, document: string, documentCreated: bool}
     */
    public static function init(string $projectRoot, bool $force = false): array
    {
        $projectRoot = rtrim($projectRoot, '/\\');
        $target = $projectRoot . DIRECTORY_SEPARATOR . Config::FILE_NAME;
        if (is_file($target) && !$force) {
            throw new \RuntimeException("{$target} already exists (use --force to overwrite).");
        }

        $stateDir = $projectRoot . DIRECTORY_SEPARATOR . '.avp';
        if (file_exists($stateDir) && !is_dir($stateDir)) {
            throw new \RuntimeException("{$stateDir} exists but is not a folder; remove or rename it and run init again.");
        }

        $template = file_get_contents(self::packageRoot() . '/config/avp.config.example.php');
        if ($template === false || @file_put_contents($target, $template) === false) {
            throw new \RuntimeException("Could not write {$target}.");
        }
        if (!is_dir($stateDir) && !@mkdir($stateDir, 0777, true) && !is_dir($stateDir)) {
            throw new \RuntimeException("Could not create {$stateDir}.");
        }
        Storage::ignoreInGit($stateDir);

        require_once __DIR__ . '/MarkdownOpenApiParser.php';
        $document = Config::detectReadme($projectRoot);
        $hasEndpoints = is_file($document)
            && (\MarkdownOpenApiParser::parse((string) file_get_contents($document))['statistics']['endpoints'] ?? 0) > 0;
        $created = false;
        if (!$hasEndpoints) {
            // README.md usually describes the project, so the API goes into its own API.md.
            $document = $projectRoot . DIRECTORY_SEPARATOR . 'API.md';
            if (!is_file($document)) {
                $markdown = file_get_contents(self::packageRoot() . '/resources/templates/api-readme.md');
                if ($markdown === false || @file_put_contents($document, $markdown) === false) {
                    throw new \RuntimeException("Could not write {$document}.");
                }
                $created = true;
            }
        }
        return ['config' => $target, 'state' => $stateDir, 'document' => $document, 'documentCreated' => $created];
    }
}
