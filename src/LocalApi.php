<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform;

use PDO;

/**
 * The web client's API surface, served locally for one implicit user and one project.
 *
 * The bundled UI is the same React build as the hosted platform. When its API base ends in /api it asks
 * /api/local/status, switches to local mode, and then calls the same route names as the hosted PHP backend.
 * Responses use the hosted envelope: {statusCode, message, data}.
 */
final class LocalApi
{
    public const PROJECT_ID = 1;
    private const USER = ['id' => 1, 'name' => 'Local user', 'email' => 'local', 'role' => 'admin', 'status' => 'active'];
    private const MAX_RESPONSE_BYTES = 10 * 1024 * 1024;
    /** Same limit as the UI's import check, so no README is parsed that the UI would refuse anyway. */
    private const MAX_PARSE_BYTES = 5 * 1024 * 1024;

    private PDO $pdo;

    public function __construct(private VersionService $versions, Storage $storage, private string $projectRoot, private string $readmePath)
    {
        $this->pdo = $storage->pdo();
    }

    /** Handles $route (the path after /api). Returns false when the route is not part of this surface. */
    public function handle(string $route, string $method): bool
    {
        $routes = [
            'GET /local/status' => fn () => ['mode' => 'local', 'projectId' => self::PROJECT_ID],
            'GET /local/readme' => fn () => $this->readmeStatus(),
            'POST /local/readme/parse' => fn () => $this->parseReadme(),
            'POST /local/readme/save' => fn () => $this->saveReadme(),
            'GET /auth/fetch/getProfile.php' => fn () => self::USER,
            'GET /projects/fetch/getProjects.php' => fn () => [$this->project()],
            'GET /projects/fetch/getProject.php' => fn () => $this->project(),
            'POST /projects/modify/insertUpdateProject.php' => fn () => $this->updateProject(),
            'POST /projects/modify/updateEnvironments.php' => fn () => $this->updateEnvironments(),
            'GET /versions/fetch/getVersions.php' => fn () => $this->versionList(),
            'GET /versions/fetch/getVersion.php' => fn () => $this->version(),
            'GET /versions/fetch/getLatestVersion.php' => fn () => $this->latestVersion(),
            'POST /versions/modify/insertUpdateVersion.php' => fn () => $this->saveVersion(),
            'POST /versions/remove/removeVersion.php' => fn () => $this->removeVersion(),
            'POST /runner/execute.php' => fn () => $this->execute(),
            'GET /request_history/fetch/getHistory.php' => fn () => $this->history(),
            'POST /request_history/remove/clearHistory.php' => fn () => $this->clearHistory(),
        ];
        $handler = $routes["{$method} {$route}"] ?? null;
        if ($handler === null) {
            if (!str_ends_with($route, '.php')) return false;
            self::send(404, 'This feature is not available in the local package.');
        }
        try {
            $data = $handler();
            $created = $route === '/versions/modify/insertUpdateVersion.php' && empty($this->input()['id']);
            self::send($created ? 201 : 200, 'Success', $data);
        } catch (\OutOfBoundsException $e) {
            self::send(404, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            self::send(400, $e->getMessage());
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') self::send(409, 'A version with this label already exists.');
            throw $e;
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
        $input ??= json_decode((string) file_get_contents('php://input'), true);
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
        return \MarkdownOpenApiParser::parse($markdown);
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
            'owner_id' => self::USER['id'],
            'owner' => self::USER,
            'members' => [],
            'versions' => $versions,
            'versions_count' => count($versions),
            'members_count' => 0,
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
        $note = trim(self::text($data, 'note'));
        $id = is_scalar($data['id'] ?? null) ? (int) $data['id'] : 0;
        if ($id > 0) {
            $this->versions->update($id, isset($data['version']) ? self::text($data, 'version') : null, $note, $openapi);
            $version = $this->versions->byId($id) ?? [];
        } else {
            if ($openapi === null) throw new \InvalidArgumentException('openapi specification is required');
            $version = $this->versions->createFromOpenApi(self::text($data, 'version'), $note, $openapi);
        }
        return $this->versionRow($version, (int) $version['id'] === $this->versions->newestId());
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
            'author_id' => self::USER['id'],
            'author_name' => self::USER['name'],
            'author_email' => self::USER['email'],
            'statistics' => $row['statistics'] ?? null,
            'warnings' => $row['warnings'] ?? [],
        ];
        if (array_key_exists('openapi', $row)) $result['openapi'] = $row['openapi'];
        return $result;
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
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $headerLines,
            'content' => $payload,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 5,
            'timeout' => 30,
            'protocol_version' => 1.1,
        ]]);

        set_time_limit(60); // longer than the 30 s request timeout, so a slow API fails with a message, not a fatal error
        $start = microtime(true);
        $http_response_header = [];
        $responseBody = @file_get_contents($url, false, $context, 0, self::MAX_RESPONSE_BYTES);
        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $rawHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : $http_response_header;
        if ($responseBody === false && !$rawHeaders) {
            $reason = error_get_last()['message'] ?? 'no response';
            throw new \RuntimeException('Request execution failed: ' . preg_replace('/^file_get_contents\([^)]*\):\s*/', '', $reason));
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

        if (!empty($data['save_history'])) {
            $this->pdo->prepare('INSERT INTO request_history (environment, method, url, request_headers, request_body, response_status, response_headers, response_body, duration_ms, created_at)
                VALUES (:environment, :method, :url, :request_headers, :request_body, :response_status, :response_headers, :response_body, :duration_ms, :created_at)')
                ->execute([
                    'environment' => trim(is_scalar($data['environment'] ?? null) ? (string) $data['environment'] : '') ?: null,
                    'method' => $method, 'url' => $url,
                    'request_headers' => json_encode($headers), 'request_body' => $payload,
                    'response_status' => $status, 'response_headers' => json_encode($responseHeaders),
                    'response_body' => substr($responseBody, 0, 100000), 'duration_ms' => $durationMs,
                    'created_at' => gmdate('c'),
                ]);
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $parsedBody, 'duration_ms' => $durationMs];
    }

    /** @return list<array<string, mixed>> */
    private function history(): array
    {
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 100)));
        $rows = $this->pdo->query("SELECT * FROM request_history ORDER BY id DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $row) => $row + [
            'project_id' => self::PROJECT_ID,
            'user_id' => self::USER['id'],
            'user_name' => self::USER['name'],
            'user_email' => self::USER['email'],
        ], $rows);
    }

    /** @return array{project_id: int} */
    private function clearHistory(): array
    {
        $this->pdo->exec('DELETE FROM request_history');
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
        $stmt = $this->pdo->prepare('SELECT value FROM metadata WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === '' ? null : (string) $value;
    }

    private function setMetadata(string $key, string $value): void
    {
        $this->pdo->prepare('INSERT INTO metadata (key, value) VALUES (:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute(['key' => $key, 'value' => $value]);
    }
}
