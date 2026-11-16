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
    private const TIMEOUT = 30;

    public function __construct(private string $stateDir, private string $defaultUrl = self::DEFAULT_URL)
    {
    }

    /** @return array{connected: bool, url: string, user: array<string, mixed>|null} */
    public function status(): array
    {
        $state = $this->load();
        return ['connected' => $state !== null && ($state['token'] ?? '') !== '', 'url' => $state['url'] ?? $this->defaultUrl, 'user' => $state['user'] ?? null];
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

    /** @return array{id: int, version: string, note: ?string, openapi: object} */
    public function version(int $versionId): array
    {
        $data = $this->authed('GET', '/versions/fetch/getVersion.php', ['id' => $versionId]);
        $openapi = is_object($data) ? ($data->openapi ?? null) : null;
        if (is_string($openapi)) $openapi = json_decode($openapi);
        if (!is_object($openapi)) throw new CloudException('That cloud version has no OpenAPI document.', 404);
        return ['id' => $versionId, 'version' => (string) ($data->version ?? ''), 'note' => isset($data->note) ? (string) $data->note : null, 'openapi' => $openapi];
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
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => $headers, 'content' => $body, 'ignore_errors' => true,
            'follow_location' => 0, 'timeout' => self::TIMEOUT, 'protocol_version' => 1.1,
        ]]);
        $text = @file_get_contents($url, false, $context, 0, 20 * 1024 * 1024);
        if ($text === false) {
            $reason = preg_replace('/^file_get_contents\([^)]*\):\s*/', '', error_get_last()['message'] ?? 'no response');
            $hint = str_contains((string) $reason, 'SSL') ? ' (PHP may be missing a CA certificate bundle: set openssl.cafile in php.ini)' : '';
            throw new CloudException("Could not reach the cloud server at {$api}: {$reason}{$hint}");
        }
        $envelope = json_decode($text);
        if (!is_object($envelope)) {
            throw new CloudException('The cloud server returned something that is not the platform API. Check the cloud URL.');
        }
        $code = (int) ($envelope->statusCode ?? 0);
        if ($code >= 400 || $code === 0) {
            throw new CloudException((string) ($envelope->message ?? 'The cloud server rejected the request.'), $code >= 400 ? $code : 502);
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
