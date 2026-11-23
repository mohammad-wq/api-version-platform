<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform;

/**
 * The web client's API surface for one local project; it has no account or sign-in model.
 *
 * The bundled UI is the same React build as the hosted platform. When its API base ends in /api it asks
 * /api/local/status, switches to local mode, and then calls the same route names as the hosted PHP backend.
 * Responses use the hosted envelope: {statusCode, message, data}.
 */
final class LocalApi
{
    public const PROJECT_ID = 1;
    private const MAX_RESPONSE_BYTES = 10 * 1024 * 1024;
    /** Seconds the tester waits for an API: per request, and for a request with its redirects. */
    private const HOP_TIMEOUT = 30;
    private const TOTAL_TIMEOUT = 110;
    /** Shorter on Windows, where PHP's built-in server cannot run workers and every other request waits meanwhile. */
    private const WINDOWS_HOP_TIMEOUT = 15;
    private const WINDOWS_TOTAL_TIMEOUT = 25;
    /** Same limit as the UI's import check, so no README is parsed that the UI would refuse anyway. */
    private const MAX_PARSE_BYTES = 5 * 1024 * 1024;

    private Cloud $cloud;

    public function __construct(private VersionService $versions, private Storage $storage, private string $projectRoot, private string $readmePath, string $cloudUrl = Cloud::DEFAULT_URL)
    {
        $this->cloud = new Cloud($storage->directory(), $cloudUrl);
    }

    /** Handles $route (the path after /api). Returns false when the route is not part of this surface. */
    public function handle(string $route, string $method): bool
    {
        $routes = [
            'GET /local/status' => fn () => ['mode' => 'local', 'projectId' => self::PROJECT_ID],
            'GET /local/readme' => fn () => $this->readmeStatus(),
            'POST /local/readme/parse' => fn () => $this->parseReadme(),
            'POST /local/readme/save' => fn () => $this->saveReadme(),
            'GET /projects/fetch/getProjects.php' => fn () => [$this->project()],
            'GET /projects/fetch/getProject.php' => fn () => $this->project(),
            'POST /projects/modify/insertUpdateProject.php' => fn () => $this->updateProject(),
            'POST /projects/modify/updateEnvironments.php' => fn () => $this->updateEnvironments(),
            'GET /versions/fetch/getVersions.php' => fn () => $this->versionList(),
            'GET /versions/fetch/getVersion.php' => fn () => $this->version(),
            'GET /versions/fetch/getLatestVersion.php' => fn () => $this->latestVersion(),
            'POST /versions/modify/insertUpdateVersion.php' => fn () => $this->saveVersion(),
            'POST /versions/remove/removeVersion.php' => fn () => $this->removeVersion(),
            'GET /cloud/status' => fn () => $this->cloudStatus(),
            'POST /cloud/login' => fn () => $this->cloudLogin(),
            'POST /cloud/logout' => fn () => $this->cloudLogout(),
            'POST /cloud/publish' => fn () => $this->cloudPublish(),
            'POST /runner/execute.php' => fn () => $this->execute(),
            'GET /request_history/fetch/getHistory.php' => fn () => $this->history(),
            'POST /request_history/remove/clearHistory.php' => fn () => $this->clearHistory(),
        ];
        $handler = $routes["{$method} {$route}"] ?? null;
        if ($handler === null) {
            if (!str_ends_with($route, '.php')) return false;
            foreach (array_keys($routes) as $known) {
                if (substr($known, strpos($known, ' ') + 1) === $route) self::send(405, 'Method not allowed: use ' . strtok($known, ' ') . ' for this route.');
            }
            self::send(404, 'This feature is not available in the local package.');
        }
        try {
            $data = $handler();
            // A new version answers 201 whether it came from an import or from the README banner (as in npm).
            $created = ($route === '/versions/modify/insertUpdateVersion.php' && empty($this->input()['id'])) || $route === '/local/readme/save';
            self::send($created ? 201 : 200, 'Success', $data);
        } catch (CloudException $e) {
            self::send($e->status, $e->getMessage());
        } catch (\OutOfBoundsException $e) {
            self::send(404, $e->getMessage());
        } catch (\OverflowException $e) {
            self::send(409, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            self::send(400, $e->getMessage());
        } catch (\RuntimeException $e) {
            self::send(422, $e->getMessage());
        }
    }

    public static function send(int $status, string $message, mixed $data = null): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        // Versions change underneath the UI (CLI saves, other tabs); a cached list would show stale APIs.
        header('Cache-Control: no-store');
        echo json_encode(['statusCode' => $status, 'message' => $message, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        static $input = null;
        if ($input === null) {
            $body = (string) file_get_contents('php://input');
            $input = trim($body) === '' ? [] : json_decode($body, true);
            if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('The request body is not valid JSON.');
            }
        }
        return is_array($input) ? $input : [];
    }

    /** A request field as a string. Arrays and objects are rejected instead of turning into "Array". */
    private static function text(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException("{$key} must be a string");
        }
        return (string) $value;
    }

    // README working copy

    /** @return array<string, mixed> */
    private function readmeStatus(): array
    {
        $status = $this->versions->status();
        $changed = in_array($status['state'], ['modified', 'uninitialized'], true);
        return [
            'exists' => $status['state'] !== 'missing',
            'path' => $this->displayPath($this->readmePath),
            'state' => $status['state'],
            'message' => $status['message'] ?? null,
            'hasChanges' => $changed,
            'latestVersionNumber' => $status['latest_version'] ?? null,
            'nextVersionNumber' => $this->versions->nextLabel(),
            'summary' => $changed ? $this->versions->workingCopySummary() : null,
        ];
    }

    /**
     * Parses client-supplied Markdown with the same PHP parser the CLI uses. The UI asks for this
     * before saving a README version, so a version created in the browser stores exactly the
     * OpenAPI a `avp version create` of the same README would — same statistics, same content hash.
     *
     * @return array{openapi: array, warnings: list<array<string, mixed>>, statistics: array<string, mixed>}
     */
    private function parseReadme(): array
    {
        $markdown = self::text($this->input(), 'markdown');
        if (trim($markdown) === '') {
            throw new \InvalidArgumentException('markdown is required');
        }
        if (strlen($markdown) > self::MAX_PARSE_BYTES) {
            // 413 like the hosted backend, so the UI shows one "too large" answer in both products.
            self::send(413, 'README Markdown is too large (5 MB maximum).');
        }
        $result = MarkdownOpenApiParser::parse($markdown);
        return $result;
    }

    /** Saves the working README as a version. The client sends the label it showed; otherwise the next one is suggested. */
    /** @return array<string, mixed> */
    private function saveReadme(): array
    {
        $data = $this->input();
        $label = trim(self::text($data, 'label'));
        if ($label === '') {
            $label = $this->versions->nextLabel();
        }
        $note = trim(self::text($data, 'note'));
        $created = $this->versions->create($label, $note);
        return $this->versionRow($this->versions->byId((int) $created['id']) ?? [], true);
    }

    // Project

    /** @return array<string, mixed> */
    private function project(): array
    {
        $versions = $this->versionList();
        $latest = $versions[0]['version'] ?? null;
        return [
            'id' => self::PROJECT_ID,
            'name' => $this->metadata('project.name') ?? basename($this->projectRoot),
            'description' => $this->metadata('project.description'),
            'environments' => json_decode($this->metadata('project.environments') ?? '[]', true) ?: [],
            'versions' => $versions,
            'versions_count' => count($versions),
            'latest_version' => $latest,
            'created_at' => $this->metadata('project.created_at') ?? $this->initCreatedAt(),
        ];
    }

    /** @return array<string, mixed> */
    private function updateProject(): array
    {
        $data = $this->input();
        $name = trim(self::text($data, 'name'));
        if ($name === '') throw new \InvalidArgumentException('Project name is required');
        if (strlen($name) > 190) throw new \InvalidArgumentException('Project name is too long (190 characters maximum)');
        $this->setMetadata('project.name', $name);
        $this->setMetadata('project.description', trim(self::text($data, 'description')));
        if (array_key_exists('environments', $data)) {
            $this->setMetadata('project.environments', json_encode($this->cleanEnvironments($data['environments']), JSON_UNESCAPED_SLASHES));
        }
        return ['project_id' => self::PROJECT_ID, 'name' => $name];
    }

    /** @return array<string, mixed> */
    private function updateEnvironments(): array
    {
        $clean = $this->cleanEnvironments($this->input()['environments'] ?? null);
        $this->setMetadata('project.environments', json_encode($clean, JSON_UNESCAPED_SLASHES));
        return ['project_id' => self::PROJECT_ID, 'environments' => $clean];
    }

    /** Same rules as the hosted updateEnvironments.php. @return list<array<string, string>> */
    private function cleanEnvironments(mixed $environments): array
    {
        if (!is_array($environments)) throw new \InvalidArgumentException('environments must be an array');
        if (count($environments) > 50) throw new \InvalidArgumentException('A project can hold at most 50 environments');
        $clean = [];
        $seenIds = [];
        $seenUrls = [];
        foreach (array_values($environments) as $index => $environment) {
            $label = 'Environment ' . ($index + 1);
            if (!is_array($environment)) throw new \InvalidArgumentException("{$label} must be an object");
            $id = trim(self::text($environment, 'id'));
            $name = trim(self::text($environment, 'name'));
            $baseUrl = rtrim(trim(self::text($environment, 'baseUrl')), '/');
            if ($id === '' || strlen($id) > 100) throw new \InvalidArgumentException("{$label} needs an id of at most 100 characters");
            if (isset($seenIds[$id])) throw new \InvalidArgumentException("{$label} reuses the id \"{$id}\"");
            if (strlen($name) > 80) throw new \InvalidArgumentException("{$label} name is too long (80 characters maximum)");
            if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $baseUrl)) {
                throw new \InvalidArgumentException(($name !== '' ? "\"{$name}\"" : $label) . ' needs an absolute http(s) base URL');
            }
            if (isset($seenUrls[strtolower($baseUrl)])) throw new \InvalidArgumentException("Two environments share the base URL {$baseUrl}");
            $seenIds[$id] = true;
            $seenUrls[strtolower($baseUrl)] = true;
            $entry = ['id' => $id, 'name' => $name, 'baseUrl' => $baseUrl];
            if (($environment['source'] ?? null) === 'readme') $entry['source'] = 'readme';
            if (($environment['insecure'] ?? false) === true) $entry['insecure'] = true;
            $clean[] = $entry;
        }
        return $clean;
    }

    // Versions

    /** @return list<array<string, mixed>> Newest first */
    private function versionList(): array
    {
        $newest = $this->versions->newestId();
        return array_map(fn (array $row) => $this->versionRow($row, (int) $row['id'] === $newest), $this->versions->all());
    }

    /** @return array<string, mixed> */
    private function version(): array
    {
        $version = isset($_GET['id']) ? $this->versions->byId(is_string($_GET['id']) ? (int) $_GET['id'] : 0) : $this->versions->get(self::text($_GET, 'version'));
        if ($version === null) throw new \OutOfBoundsException('Version not found');
        return $this->versionRow($version, (int) $version['id'] === $this->versions->newestId());
    }

    /** @return array<string, mixed> */
    private function latestVersion(): array
    {
        $newest = $this->versions->newestId();
        $version = $newest === null ? null : $this->versions->byId($newest);
        if ($version === null) throw new \OutOfBoundsException('No versions found for this project');
        return $this->versionRow($version, true);
    }

    /** @return array<string, mixed> */
    private function saveVersion(): array
    {
        $data = $this->input();
        $openapi = $data['openapi'] ?? null;
        if (is_string($openapi)) $openapi = json_decode($openapi, true);
        if ($openapi !== null && (!is_array($openapi) || !isset($openapi['openapi']) || !is_array($openapi['paths'] ?? null))) {
            throw new \InvalidArgumentException('openapi must be an OpenAPI document with "openapi" and "paths"');
        }
        $openapiJson = $openapi === null ? null : $this->openapiJsonKeepingObjects();
        $note = trim(self::text($data, 'note'));
        $id = is_scalar($data['id'] ?? null) ? (int) $data['id'] : 0;
        if ($id > 0) {
            $this->versions->update($id, isset($data['version']) ? self::text($data, 'version') : null, $note, $openapi, $openapiJson);
            $version = $this->versions->byId($id) ?? [];
        } else {
            if ($openapi === null) throw new \InvalidArgumentException('openapi specification is required');
            $version = $this->versions->createFromOpenApi(self::text($data, 'version'), $note, $openapi, $openapiJson);
        }
        return $this->versionRow($version, (int) $version['id'] === $this->versions->newestId());
    }

    /**
     * The request's OpenAPI document re-encoded with its JSON objects kept as objects. input() decodes to
     * PHP arrays, which would store every empty object `{}` (e.g. "properties": {}) as `[]`.
     */
    private function openapiJsonKeepingObjects(): ?string
    {
        $body = json_decode((string) file_get_contents('php://input'));
        $openapi = is_object($body) ? ($body->openapi ?? null) : null;
        if (is_string($openapi)) $openapi = json_decode($openapi);
        return is_object($openapi) ? json_encode($openapi, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;
    }

    /** @return array{id: int} */
    private function removeVersion(): array
    {
        $id = $this->input()['id'] ?? $this->input()['version_id'] ?? 0;
        $id = is_scalar($id) ? (int) $id : 0;
        $this->versions->delete($id);
        return ['id' => $id];
    }

    /** Maps a package version row to the hosted backend's field names. @return array<string, mixed> */
    private function versionRow(array $row, bool $isLatest): array
    {
        $result = [
            'id' => (int) $row['id'],
            'project_id' => self::PROJECT_ID,
            'version' => $row['label'],
            'note' => $row['note'],
            'content_hash' => $row['openapi_hash'],
            'source_hash' => $row['source_hash'],
            'from_readme' => $row['source_hash'] !== null,
            'is_latest' => $isLatest,
            'created_at' => $row['created_at'],
            'statistics' => $row['statistics'] ?? null,
            'warnings' => $row['warnings'] ?? [],
        ];
        if (array_key_exists('openapi', $row)) $result['openapi'] = $row['openapi'];
        return $result;
    }

    // Cloud (the hosted platform): sign in and publish. The cloud's address is never sent to the UI.

    /** @return array{connected: bool, user: array<string, mixed>|null} */
    private function cloudStatus(): array
    {
        $status = $this->cloud->status();
        return ['connected' => $status['connected'], 'user' => $status['user']];
    }

    /** @return array{connected: bool, user: array<string, mixed>|null} */
    private function cloudLogin(): array
    {
        $data = $this->input();
        $this->cloud->login(null, self::text($data, 'email'), self::text($data, 'password'));
        return $this->cloudStatus();
    }

    /** @return array{connected: bool} */
    private function cloudLogout(): array
    {
        $this->cloud->logout();
        return ['connected' => false];
    }

    /**
     * Publishes a local version to the cloud project that has this project's name (created when missing).
     *
     * @return array<string, mixed>
     */
    private function cloudPublish(): array
    {
        $data = $this->input();
        $publisher = new Publisher($this->versions, $this->storage, $this->cloud, $this->projectRoot);
        $result = $publisher->publishLocal(
            is_scalar($data['version_id'] ?? null) ? (int) $data['version_id'] : 0,
            isset($data['version']) ? self::text($data, 'version') : null,
            isset($data['note']) ? self::text($data, 'note') : null,
        );
        return ['version' => $result['version'], 'project' => ['name' => $result['project']['name']], 'projectCreated' => $result['project']['created']];
    }

    // API testing

    /** Sends the request the Test APIs tab built. @return array<string, mixed> */
    private function execute(): array
    {
        $data = $this->input();
        $method = strtoupper(trim(self::text($data, 'method', 'GET')));
        $url = trim(self::text($data, 'url'));
        $headers = is_array($data['headers'] ?? null) ? $data['headers'] : [];
        $body = $data['body'] ?? null;
        // A request that is refused still goes into the History (with the reason), so the list shows every attempt.
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            $this->refuse($data, $method, $url, 'Invalid target URL');
        }
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
            $this->refuse($data, $method, $url, "Unsupported HTTP method: {$method}");
        }
        if (self::isOwnAddress($url)) {
            // PHP's built-in server answers one request at a time on Windows: calling itself would wait for itself until the timeout.
            $this->refuse($data, $method, $url, "The tester cannot call this tool's own address.");
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            // A list of values is one header with comma-separated values (RFC 9110); other non-scalars are dropped.
            if (is_array($value) && array_filter($value, 'is_scalar') === $value) $value = implode(', ', $value);
            if (!is_string($name) || !is_scalar($value)) continue;
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            if (!preg_match('/[\r\n]/', $name . $value)) $headerLines[] = "{$name}: {$value}";
        }
        $payload = $body === null ? null : (is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES) : (is_bool($body) ? json_encode($body) : (string) $body));
        // Windows has no PHP_CLI_SERVER_WORKERS: the whole UI waits while this request runs, so it gets a short budget there.
        $windows = PHP_OS_FAMILY === 'Windows';
        $perHopSeconds = $windows ? self::WINDOWS_HOP_TIMEOUT : self::HOP_TIMEOUT;
        $budgetSeconds = $windows ? self::WINDOWS_TOTAL_TIMEOUT : self::TOTAL_TIMEOUT;
        set_time_limit($budgetSeconds + 15); // above the budget, so a slow API fails with a message, not a fatal error
        $start = microtime(true);
        $deadline = $start + $budgetSeconds;
        $hopTimeout = (float) $perHopSeconds;
        $hopStarted = $start;
        $truncated = false;
        $startedPrivate = self::isPrivateHost((string) parse_url($url, PHP_URL_HOST));
        $currentUrl = $url;
        $currentMethod = $method;
        $currentPayload = $payload;
        $blockedRedirect = null;
        // Redirects are followed here (at most 5) so a public API cannot bounce the request into a private address.
        $failure = [];
        for ($hop = 0;; $hop++) {
            $hopTimeout = max(1.0, min((float) $perHopSeconds, $deadline - microtime(true)));
            $hopStarted = microtime(true);
            $options = ['http' => [
                'method' => $currentMethod,
                'header' => $headerLines,
                'content' => $currentPayload,
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => $hopTimeout,
                'protocol_version' => 1.1,
            ]];
            if ($this->skipsCertificateCheck($currentUrl)) {
                // The user switched this on for this environment (a QA/internal server with a self-signed certificate).
                $options['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true];
            }
            $context = stream_context_create($options);
            $http_response_header = [];
            // Keep every warning PHP raises: the last one alone ("Failed to open stream") hides the real reason.
            $failure = [];
            set_error_handler(static function (int $level, string $message) use (&$failure): bool {
                $failure[] = trim(html_entity_decode((string) preg_replace('/^file_get_contents\([^)]*\):\s*/', '', $message), ENT_QUOTES));
                return true;
            });
            try {
                // One byte more than the cap, so a body that is exactly the cap is not reported as cut.
                $responseBody = file_get_contents($currentUrl, false, $context, 0, self::MAX_RESPONSE_BYTES + 1);
            } finally {
                restore_error_handler();
            }
            $truncated = is_string($responseBody) && strlen($responseBody) > self::MAX_RESPONSE_BYTES;
            if ($truncated) {
                $responseBody = substr($responseBody, 0, self::MAX_RESPONSE_BYTES);
            }
            $rawHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : $http_response_header;
            $hopStatus = isset($rawHeaders[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $rawHeaders[0], $m) ? (int) $m[1] : null;
            $location = null;
            foreach ($rawHeaders as $line) {
                if (stripos($line, 'location:') === 0) $location = trim(substr($line, 9));
            }
            if (!in_array($hopStatus, [301, 302, 303, 307, 308], true) || $location === null || $location === '') break;
            $next = self::resolveRedirect($currentUrl, $location);
            if ($next === null || $hop >= 5) break; // leave the redirect response itself as the answer
            if (!$startedPrivate && self::isPrivateHost((string) parse_url($next, PHP_URL_HOST))) {
                $blockedRedirect = $next;
                break;
            }
            if ($hopStatus === 303 || (in_array($hopStatus, [301, 302], true) && $currentMethod === 'POST')) {
                $currentMethod = 'GET';
                $currentPayload = null;
            }
            $currentUrl = $next;
        }
        $durationMs = (int) round((microtime(true) - $start) * 1000);
        if ($blockedRedirect !== null) {
            $message = "Request blocked: the server redirected to a private address (" . self::redactUrl($blockedRedirect) . ').';
            $this->recordHistory($data, $method, $url, null, $durationMs, $message);
            throw new \RuntimeException($message);
        }
        if ($responseBody === false && !$rawHeaders) {
            if (microtime(true) - $hopStarted >= $hopTimeout - 0.5) {
                $seconds = (int) round($hopTimeout);
                $message = "Request execution failed: the API did not answer within {$seconds} seconds."
                    . ($windows ? ' Windows runs one request at a time, so the tester waits at most ' . self::WINDOWS_TOTAL_TIMEOUT . ' seconds.' : '');
            } else {
                $message = 'Request execution failed: ' . self::failureReason($failure, $currentUrl);
            }
            $this->recordHistory($data, $method, $url, null, $durationMs, $message);
            throw new \RuntimeException($message);
        }

        // After redirects the headers hold every hop; keep the final response's.
        $status = null;
        $responseHeaders = [];
        foreach ($rawHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $responseHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $responseHeaders[trim($key)] = trim($value);
            }
        }
        $responseBody = (string) $responseBody;
        $parsed = json_decode($responseBody, true);
        $parsedBody = json_last_error() === JSON_ERROR_NONE ? $parsed : $responseBody;

        $this->recordHistory($data, $method, $url, $status, $durationMs);

        // `binary` is always false here (invalid UTF-8 is substituted when the response is encoded); the hosted runner uses it for raw bytes.
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $parsedBody, 'duration_ms' => $durationMs, 'truncated' => $truncated, 'size' => strlen($responseBody), 'binary' => false];
    }

    /** Records the refused request in the History, then answers 400. @param array<string, mixed> $data @return never */
    private function refuse(array $data, string $method, string $url, string $message): void
    {
        $this->recordHistory($data, $method, $url, null, 0, $message);
        throw new \InvalidArgumentException($message);
    }

    /** True when $url points at this very server (same port and a loopback or the bound host). */
    private static function isOwnAddress(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) return false;
        $port = (int) ($parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? 'http')) === 'https' ? 443 : 80));
        $ownPort = (int) ($_SERVER['SERVER_PORT'] ?? 0);
        if ($ownPort === 0 || $port !== $ownPort) return false;
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $own = ['localhost', '::1'];
        foreach ([getenv('AVP_SERVER_HOST') ?: '', (string) ($_SERVER['SERVER_NAME'] ?? ''), preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))] as $name) {
            $name = strtolower(trim((string) $name, '[]'));
            if ($name !== '' && $name !== '0.0.0.0') $own[] = $name;
        }
        return in_array($host, $own, true) || str_starts_with($host, '127.');
    }

    /** True when the environment this URL belongs to has "Skip TLS certificate check" switched on. */
    private function skipsCertificateCheck(string $url): bool
    {
        if (!preg_match('#^https://#i', $url)) return false;
        $environments = json_decode($this->metadata('project.environments') ?? '[]', true);
        $target = strtolower($url);
        foreach (is_array($environments) ? $environments : [] as $environment) {
            if (!is_array($environment) || ($environment['insecure'] ?? false) !== true) continue;
            $base = strtolower(rtrim((string) ($environment['baseUrl'] ?? ''), '/'));
            if ($base !== '' && str_starts_with($target, $base) && (strlen($target) === strlen($base) || in_array($target[strlen($base)], ['/', '?', '#'], true))) {
                return true;
            }
        }
        return false;
    }

    /**
     * What went wrong with a request that got no response, in words a person can act on.
     *
     * @param list<string> $warnings the PHP warnings raised while connecting
     */
    private static function failureReason(array $warnings, string $url): string
    {
        $text = implode(' ', $warnings);
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        if (stripos($text, 'allow_url_fopen') !== false) {
            return "PHP's allow_url_fopen setting is off, so the tester cannot make requests. Set allow_url_fopen=On in php.ini and restart avp serve.";
        }
        if (preg_match('/Unable to find the wrapper\s*"https"/i', $text)) {
            return "PHP's openssl extension is not enabled, so HTTPS requests cannot be made. Enable extension=openssl in php.ini and restart avp serve.";
        }
        if (preg_match('/certificate verify failed|SSL operation failed|Failed to enable crypto|unable to get local issuer|self[- ]signed|SSL: Handshake/i', $text)) {
            $detail = 'certificate verify failed';
            foreach ($warnings as $warning) {
                // OpenSSL's own wording, e.g. "error:0A000086:SSL routines::certificate verify failed".
                if (preg_match('/error:[0-9A-Fa-f]+:[^:\n]+::([^\n]+)/', $warning, $m)) {
                    $detail = trim($m[1]);
                    break;
                }
            }
            $cafile = trim((string) ini_get('openssl.cafile'));
            return "the TLS certificate of {$host} could not be verified ({$detail}). For a QA or internal server with a self-signed certificate, "
                . 'open the environment settings and turn on "Skip TLS certificate check". If every HTTPS site fails like this, PHP\'s CA certificate file is missing or out of date'
                . ($cafile !== '' ? " (openssl.cafile = {$cafile})" : ' (openssl.cafile is not set in php.ini)') . '.';
        }
        foreach ($warnings as $warning) {
            // The generic closing warning says nothing; the one before it names the cause (DNS, refused, timed out, ...).
            if ($warning !== '' && !preg_match('/^Failed to open stream: operation failed$/i', $warning)) return $warning;
        }
        return 'PHP could not open the request stream. Check the server URL, port, network access, and TLS certificate.';
    }

    /** True for loopback, link-local and private-range hosts (the ones a public API must not redirect a request to). */
    private static function isPrivateHost(string $host): bool
    {
        $host = trim(strtolower($host), '[]');
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) return true;
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false; // unresolvable: the request itself will fail
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** An absolute http(s) URL for a Location header, or null when it is not one. */
    private static function resolveRedirect(string $base, string $location): ?string
    {
        if (preg_match('#^https?://#i', $location)) return $location;
        $parts = parse_url($base);
        if (!isset($parts['scheme'], $parts['host'])) return null;
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) return $parts['scheme'] . ':' . $location;
        if (str_starts_with($location, '/')) return $origin . $location;
        $dir = preg_replace('#[^/]*$#', '', $parts['path'] ?? '/');
        return $origin . ($dir === '' ? '/' : $dir) . $location;
    }

    /** Names whose values never reach the history; same rule as the hosted backend and the npm package. */
    private const HISTORY_SECRET_NAME = '/(authorization|cookie|session|token|secret|passw(or)?d|credential|api[-_]?key|private[-_]?key)/i';

    /**
     * Records a Test APIs call (also one that got no response: status null). Only the method, URL, status and
     * timing are kept, with secret-looking query values redacted. A history problem never turns the request into an error.
     *
     * @param array<string, mixed> $data
     */
    private function recordHistory(array $data, string $method, string $url, ?int $status, int $durationMs, ?string $error = null): void
    {
        if (empty($data['save_history'])) return;
        try {
            $this->storage->addHistory([
                'environment' => trim(is_scalar($data['environment'] ?? null) ? (string) $data['environment'] : '') ?: null,
                // A refused request can carry any text as its URL or method: keep the row small and printable.
                'method' => substr($method, 0, 16), 'url' => substr(self::redactUrl($url), 0, 2000),
                'response_status' => $status, 'duration_ms' => $durationMs, 'created_at' => gmdate('c'),
                'error' => $error !== null && $error !== '' ? substr($error, 0, 500) : null,
            ]);
        } catch (\Throwable $e) {
            error_log('Could not save the request history: ' . $e->getMessage());
        }
    }

    private static function redactUrl(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') return $url;
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        $pairs = implode('&', array_map(function (string $pair): string {
            [$name] = explode('=', $pair, 2);
            return preg_match(self::HISTORY_SECRET_NAME, urldecode($name)) === 1 ? $name . '=[REDACTED]' : $pair;
        }, explode('&', $query)));
        return substr($url, 0, (int) strpos($url, '?')) . '?' . $pairs . (is_string($fragment) ? '#' . $fragment : '');
    }

    /**
     * The request list (newest first), or with `?id=N` the one entry with every stored field.
     * Rows from before the `error` field existed read as error null.
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function history(): array
    {
        $normalize = static fn (array $row): array => [
            'id' => (int) $row['id'], 'method' => $row['method'] ?? '', 'url' => $row['url'] ?? '',
            'response_status' => $row['response_status'] ?? null, 'duration_ms' => $row['duration_ms'] ?? null,
            'created_at' => $row['created_at'] ?? null, 'error' => $row['error'] ?? null, 'has_detail' => true,
        ];
        if (isset($_GET['id'])) {
            $wanted = is_string($_GET['id']) && ctype_digit($_GET['id']) ? (int) $_GET['id'] : 0;
            foreach ($this->storage->history(1000) as $row) {
                if ((int) $row['id'] === $wanted) return $normalize($row) + ['environment' => $row['environment'] ?? null];
            }
            throw new \OutOfBoundsException('Request not found');
        }
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 100)));
        return array_map($normalize, $this->storage->history($limit));
    }

    /** @return array{project_id: int} */
    private function clearHistory(): array
    {
        $this->storage->clearHistory();
        return ['project_id' => self::PROJECT_ID];
    }

    // Helpers

    private function displayPath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->projectRoot), '/') . '/';
        $normalized = str_replace('\\', '/', $path);
        return str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : $normalized;
    }

    private function initCreatedAt(): string
    {
        $now = gmdate('c');
        $this->setMetadata('project.created_at', $now);
        return $now;
    }

    private function metadata(string $key): ?string
    {
        return $this->storage->meta($key);
    }

    private function setMetadata(string $key, string $value): void
    {
        $this->storage->setMeta($key, $value);
    }
}
