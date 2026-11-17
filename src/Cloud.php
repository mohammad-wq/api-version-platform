<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform;

/**
 * Client for the hosted API Version Platform (login, projects, versions).
 *
 * The browser never sees the cloud token: it is kept in the state folder (`cloud.json`) and this
 * class makes the calls from the local PHP server, so CORS does not apply.
 */
final class Cloud
{
    public const DEFAULT_URL = 'https://qa.erpsite.org.pk/test_avp/';
    private const TIMEOUT = 20;

    public function __construct(private string $stateDir, private string $defaultUrl = self::DEFAULT_URL)
    {
    }

    /** @return array{connected: bool, url: string, user: array<string, mixed>|null, insecure: bool} */
    public function status(): array
    {
        $state = $this->load();
        $url = $state['url'] ?? $this->defaultUrl;
        return ['connected' => $state !== null && ($state['token'] ?? '') !== '', 'url' => $url, 'user' => $state['user'] ?? null, 'insecure' => self::isCleartext($url)];
    }

    /** @return array{connected: bool, url: string, user: array<string, mixed>|null} */
    public function login(?string $url, string $email, string $password): array
    {
        $url = trim((string) $url) !== '' ? trim((string) $url) : $this->defaultUrl;
        $api = self::apiBase($url);
        if (trim($email) === '' || $password === '') {
            throw new \InvalidArgumentException('Email and password are required.');
        }
        $data = $this->call($api, 'POST', '/auth/auth/login.php', null, ['email' => trim($email), 'password' => $password]);
        $token = is_object($data) ? ($data->token ?? null) : null;
        if (!is_string($token) || $token === '') {
            throw new CloudException('The cloud server did not return a sign-in token.');
        }
        $user = json_decode((string) json_encode($data->user ?? null), true);
        $this->save(['url' => $url, 'token' => $token, 'user' => is_array($user) ? array_intersect_key($user, array_flip(['id', 'name', 'email', 'role'])) : null, 'saved_at' => gmdate('c')]);
        return $this->status();
    }

    public function logout(): void
    {
        @unlink($this->file());
    }

    /** @return list<array<string, mixed>> */
    public function projects(): array
    {
        $rows = $this->authed('GET', '/projects/fetch/getProjects.php');
        $list = json_decode((string) json_encode($rows), true);
        return array_map(static fn (array $p): array => [
            'id' => (int) ($p['id'] ?? $p['project_id'] ?? 0),
            'name' => (string) ($p['name'] ?? ''),
            'description' => $p['description'] ?? null,
            'latest_version' => $p['latest_version'] ?? null,
            'versions_count' => isset($p['versions_count']) ? (int) $p['versions_count'] : null,
            'owner' => $p['owner']['name'] ?? $p['owner_name'] ?? null,
        ], is_array($list) ? array_values(array_filter($list, 'is_array')) : []);
    }

    /** The signed-in user's cloud project with this name (case-insensitive, trimmed), or null. @return array<string, mixed>|null */
    public function findProject(string $name): ?array
    {
        $wanted = strtolower(trim($name));
        foreach ($this->projects() as $project) {
            if (strtolower(trim((string) $project['name'])) === $wanted) return $project;
        }
        return null;
    }

    /** @return list<array<string, mixed>> Newest first, without the OpenAPI payload */
    public function versions(int $projectId): array
    {
        $rows = json_decode((string) json_encode($this->authed('GET', '/versions/fetch/getVersions.php', ['project_id' => $projectId])), true);
        return array_map(static fn (array $v): array => [
            'id' => (int) ($v['id'] ?? 0),
            'version' => (string) ($v['version'] ?? ''),
            'note' => $v['note'] ?? null,
            'is_latest' => !empty($v['is_latest']),
            'created_at' => $v['created_at'] ?? null,
            'author_name' => $v['author_name'] ?? null,
        ], is_array($rows) ? array_values(array_filter($rows, 'is_array')) : []);
    }

    /** Creates a cloud project and returns its id. */
    public function createProject(string $name, string $description = ''): int
    {
        $name = trim($name);
        if ($name === '') throw new \InvalidArgumentException('A cloud project name is required.');
        $data = $this->authed('POST', '/projects/modify/insertUpdateProject.php', null, ['name' => $name, 'description' => $description]);
        $id = is_object($data) ? (int) ($data->project_id ?? $data->id ?? 0) : 0;
        if ($id <= 0) throw new CloudException('The cloud server did not return the new project id.');
        return $id;
    }

    /**
     * Publishes an OpenAPI document as a new cloud version.
     *
     * @return array{id: int, version: string, project_id: int}
     */
    public function publish(int $projectId, string $version, ?string $note, object $openapi): array
    {
        $data = $this->authed('POST', '/versions/modify/insertUpdateVersion.php', null, [
            'project_id' => $projectId, 'version' => $version, 'note' => $note ?? '', 'is_latest' => true, 'openapi' => $openapi,
        ]);
        return ['id' => (int) ($data->id ?? 0), 'version' => (string) ($data->version ?? $version), 'project_id' => $projectId];
    }

    // HTTP

    /** True for an http:// URL that is not this machine: a password sent there is readable on the network. */
    public static function isCleartext(string $url): bool
    {
        if (!preg_match('#^http://([^/:\s]+)#i', trim($url), $m)) return false;
        $host = strtolower($m[1]);
        return !in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    /** The backend URL for a site URL: the site's `backend` folder unless it already points at one. */
    public static function apiBase(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if (!preg_match('#^https?://[^/\s]+#i', $url)) {
            throw new \InvalidArgumentException('The cloud URL must start with http:// or https://');
        }
        return str_ends_with($url, '/backend') ? $url : $url . '/backend';
    }

    /** @param array<string, scalar>|null $query */
    private function authed(string $method, string $path, ?array $query = null, ?array $json = null): mixed
    {
        $state = $this->load();
        if ($state === null || ($state['token'] ?? '') === '') {
            throw new CloudException('Not signed in to the cloud.', 401);
        }
        try {
            return $this->call(self::apiBase($state['url']), $method, $path, $query, $json, $state['token']);
        } catch (CloudException $e) {
            if ($e->status === 401) {
                $this->logout();
                throw new CloudException('The cloud session expired. Sign in again.', 401);
            }
            throw $e;
        }
    }

    /** @return mixed The envelope's `data` (objects stay objects) */
    private function call(string $api, string $method, string $path, ?array $query, ?array $json, ?string $token = null): mixed
    {
        if (str_starts_with(strtolower($api), 'https://') && !extension_loaded('openssl')) {
            throw new CloudException('PHP\'s openssl extension is not enabled, so HTTPS cloud servers cannot be reached. Enable "extension=openssl" in php.ini.');
        }
        $headers = ['Accept: application/json'];
        if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
        $body = null;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $url = $api . $path . ($query ? '?' . http_build_query($query) : '');
        $privateAddresses = array_values(array_filter(
            [$url, $api, parse_url($api, PHP_URL_HOST)],
            static fn ($value): bool => is_string($value) && $value !== '',
        ));
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => $headers, 'content' => $body, 'ignore_errors' => true,
            'follow_location' => 0, 'timeout' => self::TIMEOUT, 'protocol_version' => 1.1,
        ]]);
        set_time_limit(60); // above the 20 s request timeout, so a slow server fails with a message, not a fatal error
        $http_response_header = [];
        $started = microtime(true);
        $text = @file_get_contents($url, false, $context, 0, 20 * 1024 * 1024);
        $responseHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : $http_response_header;
        if ($text === false) {
            $reason = preg_replace('/^file_get_contents\([^)]*\):\s*/', '', error_get_last()['message'] ?? 'no response');
            $reason = str_replace($privateAddresses, 'cloud server', (string) $reason);
            if (microtime(true) - $started >= self::TIMEOUT - 1) {
                throw new CloudException('The cloud server did not answer within ' . self::TIMEOUT . ' seconds. Check your connection, then try again.');
            }
            $hint = str_contains((string) $reason, 'SSL') ? ' (PHP may be missing a CA certificate bundle: set openssl.cafile in php.ini)' : '';
            throw new CloudException("Could not reach the cloud server: {$reason}{$hint}");
        }
        $httpStatus = isset($responseHeaders[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $m) ? (int) $m[1] : 0;
        if ($httpStatus >= 300 && $httpStatus < 400) {
            throw new CloudException('The cloud server redirected the request to another address. Check the configured cloud URL.');
        }
        $envelope = json_decode($text);
        if (!is_object($envelope)) {
            throw new CloudException('The cloud server returned something that is not the platform API. Check the cloud URL.');
        }
        $code = (int) ($envelope->statusCode ?? 0);
        if ($code >= 400 || $code === 0) {
            $message = (string) ($envelope->message ?? 'The cloud server rejected the request.');
            $message = str_replace($privateAddresses, 'cloud server', $message);
            throw new CloudException($message, $code >= 400 ? $code : 502);
        }
        return $envelope->data ?? null;
    }

    // Saved sign-in

    private function file(): string
    {
        return rtrim($this->stateDir, '/\\') . DIRECTORY_SEPARATOR . 'cloud.json';
    }

    /** @return array<string, mixed>|null */
    private function load(): ?array
    {
        $text = is_file($this->file()) ? @file_get_contents($this->file()) : false;
        $state = $text === false ? null : json_decode($text, true);
        return is_array($state) ? $state : null;
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void
    {
        $file = $this->file();
        if (@file_put_contents($file, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            throw new \RuntimeException("Could not save the cloud sign-in to {$file}");
        }
        @chmod($file, 0600);
    }
}
