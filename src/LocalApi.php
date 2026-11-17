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
            'GET /cloud/projects' => fn () => $this->cloud->projects(),
            'GET /cloud/versions' => fn () => $this->cloud->versions((int) (is_string($_GET['project_id'] ?? null) ? $_GET['project_id'] : 0)),
            'POST /cloud/publish' => fn () => $this->cloudPublish(),
            'POST /cloud/import' => fn () => $this->cloudImport(),
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
            $created = ($route === '/versions/modify/insertUpdateVersion.php' && empty($this->input()['id'])) || $route === '/local/readme/save' || $route === '/cloud/import';
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
            throw new \InvalidArgumentException('README Markdown is too large (5 MB maximum).');
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

    // Cloud (the hosted platform): sign in, browse its projects, publish and import versions

    /** @return array<string, mixed> */
    private function cloudStatus(): array
    {
        return $this->cloud->status() + [
            'linkedProject' => ($id = (int) $this->storage->meta('cloud.project_id')) > 0
                ? ['id' => $id, 'name' => $this->storage->meta('cloud.project_name'), 'url' => $this->storage->meta('cloud.project_url')] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function cloudLogin(): array
    {
        $data = $this->input();
        $status = $this->cloud->login(isset($data['url']) ? self::text($data, 'url') : null, self::text($data, 'email'), self::text($data, 'password'));
        return $status + ['linkedProject' => $this->cloudStatus()['linkedProject']];
    }

    /** @return array{connected: bool} */
    private function cloudLogout(): array
    {
        $this->cloud->logout();
        return ['connected' => false];
    }

    /**
     * Publishes a local version to a cloud project: `project_id` of an existing one, or `new_project_name` to
     * create it first. The project is remembered, so the next publish defaults to it.
     *
     * @return array<string, mixed>
     */
    private function cloudPublish(): array
    {
        $data = $this->input();
        $local = $this->versions->byId(is_scalar($data['version_id'] ?? null) ? (int) $data['version_id'] : 0);
        if ($local === null) throw new \OutOfBoundsException('Version not found');
        $projectId = is_scalar($data['project_id'] ?? null) ? (int) $data['project_id'] : 0;
        $created = false;
        if ($projectId <= 0) {
            $name = trim(self::text($data, 'new_project_name'));
            if ($name === '') throw new \InvalidArgumentException('Choose a cloud project or give a name for a new one.');
            $projectId = $this->cloud->createProject($name, trim(self::text($data, 'description')));
            $created = true;
        }
        $label = trim(self::text($data, 'version')) ?: (string) $local['label'];
        $note = trim(self::text($data, 'note')) ?: ($local['note'] ?? null);
        $published = $this->cloud->publish($projectId, $label, $note, $local['openapi']);
        $name = $created ? trim(self::text($data, 'new_project_name')) : trim(self::text($data, 'project_name'));
        $this->storage->setMeta('cloud.project_id', (string) $projectId);
        if ($name !== '') $this->storage->setMeta('cloud.project_name', $name);
        $this->storage->setMeta('cloud.project_url', rtrim((string) $this->cloud->status()['url'], '/') . '/projects/' . $projectId);
        return $published + ['projectCreated' => $created, 'url' => $this->storage->meta('cloud.project_url')];
    }

    /** Copies a cloud version into the local project as an OpenAPI-only version. @return array<string, mixed> */
    private function cloudImport(): array
    {
        $data = $this->input();
        $remote = $this->cloud->version(is_scalar($data['version_id'] ?? null) ? (int) $data['version_id'] : 0);
        $label = trim(self::text($data, 'label')) ?: $remote['version'];
        $json = json_encode($remote['openapi'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $openapi = json_decode($json, true);
        if (!is_array($openapi) || !isset($openapi['openapi']) || !is_array($openapi['paths'] ?? null)) {
            throw new \InvalidArgumentException('The cloud version is not an OpenAPI document with "openapi" and "paths".');
        }
        $note = trim(self::text($data, 'note')) ?: ($remote['note'] ?? '');
        return $this->versionRow($this->versions->createFromOpenApi($label, (string) $note, $openapi, $json), true);
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
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('Invalid target URL');
        }
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
            throw new \InvalidArgumentException("Unsupported HTTP method: {$method}");
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
        set_time_limit(120); // longer than the per-hop 30 s timeout, so a slow API fails with a message, not a fatal error
        $start = microtime(true);
        $startedPrivate = self::isPrivateHost((string) parse_url($url, PHP_URL_HOST));
        $currentUrl = $url;
        $currentMethod = $method;
        $currentPayload = $payload;
        $blockedRedirect = null;
        // Redirects are followed here (at most 5) so a public API cannot bounce the request into a private address.
        for ($hop = 0;; $hop++) {
            $context = stream_context_create(['http' => [
                'method' => $currentMethod,
                'header' => $headerLines,
                'content' => $currentPayload,
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 30,
                'protocol_version' => 1.1,
            ]]);
            $http_response_header = [];
            $responseBody = @file_get_contents($currentUrl, false, $context, 0, self::MAX_RESPONSE_BYTES);
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
            $message = "Request blocked: the server redirected to a private address ({$blockedRedirect}).";
            $this->recordHistory($data, $method, $url, null, $durationMs);
            throw new \RuntimeException($message);
        }
        if ($responseBody === false && !$rawHeaders) {
            $reason = error_get_last()['message'] ?? 'no response';
            $message = 'Request execution failed: ' . preg_replace('/^file_get_contents\([^)]*\):\s*/', '', $reason);
            $this->recordHistory($data, $method, $url, null, $durationMs);
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

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $parsedBody, 'duration_ms' => $durationMs];
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
    private function recordHistory(array $data, string $method, string $url, ?int $status, int $durationMs): void
    {
        if (empty($data['save_history'])) return;
        try {
            $this->storage->addHistory([
                'environment' => trim(is_scalar($data['environment'] ?? null) ? (string) $data['environment'] : '') ?: null,
                'method' => $method, 'url' => self::redactUrl($url),
                'response_status' => $status, 'duration_ms' => $durationMs, 'created_at' => gmdate('c'),
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

    /** @return list<array<string, mixed>> */
    private function history(): array
    {
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 100)));
        return array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['id', 'method', 'url', 'response_status', 'duration_ms', 'created_at'])), $this->storage->history($limit));
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
