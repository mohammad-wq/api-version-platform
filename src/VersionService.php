<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform;

use PDO;

final class VersionService
{
    public const MAX_LABEL_LENGTH = 50;

    private PDO $pdo;

    public function __construct(private string $readmePath, Storage $storage)
    {
        $this->pdo = $storage->pdo();
        require_once __DIR__ . '/MarkdownOpenApiParser.php';
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        if (!is_file($this->readmePath) || !is_readable($this->readmePath)) {
            return ['state' => 'missing', 'readme_path' => $this->readmePath, 'message' => "No API document found at {$this->readmePath}. Run `avp init` to create one from the template."];
        }
        $markdown = file_get_contents($this->readmePath);
        if ($markdown === false) {
            return ['state' => 'missing', 'readme_path' => $this->readmePath, 'message' => 'README file cannot be read.'];
        }
        $parse = \MarkdownOpenApiParser::parse($markdown);
        if (($parse['statistics']['endpoints'] ?? 0) === 0) {
            return ['state' => 'invalid', 'readme_path' => $this->readmePath, 'message' => "No API endpoints were found in {$this->readmePath}. See `avp template` for the format.", 'warnings' => $parse['warnings']];
        }
        $sourceHash = SourceHasher::hash($markdown);
        $latest = $this->latest();
        if ($latest === null) {
            return ['state' => 'uninitialized', 'readme_path' => $this->readmePath, 'source_hash' => $sourceHash];
        }
        return [
            'state' => hash_equals($latest['source_hash'], $sourceHash) ? 'clean' : 'modified',
            'readme_path' => $this->readmePath,
            'source_hash' => $sourceHash,
            'latest_version' => $latest['label'],
            'latest_source_hash' => $latest['source_hash'],
        ];
    }

    /** @return array<string, mixed> */
    public function create(string $label, string $note = '', bool $allowUnchanged = false): array
    {
        $label = self::label($label);
        if (!is_file($this->readmePath) || !is_readable($this->readmePath)) {
            throw new \RuntimeException("README file is missing or unreadable: {$this->readmePath}");
        }
        $markdown = file_get_contents($this->readmePath);
        if ($markdown === false) {
            throw new \RuntimeException("Could not read README: {$this->readmePath}");
        }
        $parse = \MarkdownOpenApiParser::parse($markdown);
        if (($parse['statistics']['endpoints'] ?? 0) === 0) {
            throw new \RuntimeException('No API endpoints were found; version was not created.');
        }
        $source = SourceHasher::normalize($markdown);
        $sourceHash = SourceHasher::hash($source);
        $latest = $this->latest();
        if (!$allowUnchanged && $latest !== null && hash_equals($latest['source_hash'], $sourceHash)) {
            throw new \RuntimeException('README is clean; no new version created. Use --allow-unchanged to override.');
        }
        $openapi = $parse['openapi'];
        $openapiJson = json_encode($openapi, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $openapiHash = hash('sha256', $openapiJson);
        $createdAt = gmdate('c');

        // One row: the baseline is the newest version with a source hash, so this insert also advances it.
        $this->assertLabelFree($label);
        $insert = $this->pdo->prepare('INSERT INTO versions (label, note, markdown, source_hash, openapi_json, openapi_hash, parser_warnings_json, parser_statistics_json, created_at)
            VALUES (:label, :note, :markdown, :source_hash, :openapi_json, :openapi_hash, :warnings, :statistics, :created_at)');
        $insert->execute([
            'label' => $label, 'note' => $note !== '' ? $note : null, 'markdown' => $source,
            'source_hash' => $sourceHash, 'openapi_json' => $openapiJson, 'openapi_hash' => $openapiHash,
            'warnings' => json_encode($parse['warnings'], JSON_THROW_ON_ERROR),
            'statistics' => json_encode($parse['statistics'], JSON_THROW_ON_ERROR), 'created_at' => $createdAt,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        return ['id' => $id, 'label' => $label, 'source_hash' => $sourceHash, 'openapi_hash' => $openapiHash, 'created_at' => $createdAt, 'parse' => ['warnings' => $parse['warnings'], 'statistics' => $parse['statistics']]];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT id, label, note, source_hash, openapi_hash, parser_warnings_json, parser_statistics_json, created_at FROM versions ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['warnings'] = json_decode($row['parser_warnings_json'], true);
            $row['statistics'] = json_decode($row['parser_statistics_json'], true);
            unset($row['parser_warnings_json'], $row['parser_statistics_json']);
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function get(string $label): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM versions WHERE label = :label');
        $stmt->execute(['label' => $label]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row !== null) {
            // Objects, not arrays: an array decode turns every empty object `{}` into `[]` when served.
            $row['openapi'] = json_decode($row['openapi_json']);
            $row['warnings'] = json_decode($row['parser_warnings_json'], true);
            $row['statistics'] = json_decode($row['parser_statistics_json'], true);
            unset($row['openapi_json'], $row['parser_warnings_json'], $row['parser_statistics_json']);
        }
        return $row;
    }

    /** @return array<string, mixed>|null */
    public function byId(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT label FROM versions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $label = $stmt->fetchColumn();
        return $label === false ? null : $this->get((string) $label);
    }

    /** The newest version of any kind; the UI treats it as "latest". */
    public function newestId(): ?int
    {
        $id = $this->pdo->query('SELECT MAX(id) FROM versions')->fetchColumn();
        return $id === null || $id === false ? null : (int) $id;
    }

    /**
     * Stores an OpenAPI document that did not come from the working README (UI import or edit).
     * It has no source hash, so it never moves the README baseline.
     *
     * @param array<string, mixed> $openapi
     * @return array<string, mixed>
     */
    public function createFromOpenApi(string $label, string $note, array $openapi, ?string $openapiJson = null): array
    {
        $label = self::label($label);
        $openapiJson ??= json_encode($openapi, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertLabelFree($label);
        $insert = $this->pdo->prepare('INSERT INTO versions (label, note, markdown, source_hash, openapi_json, openapi_hash, parser_warnings_json, parser_statistics_json, created_at)
            VALUES (:label, :note, NULL, NULL, :openapi_json, :openapi_hash, :warnings, :statistics, :created_at)');
        $insert->execute([
            'label' => $label, 'note' => $note !== '' ? $note : null,
            'openapi_json' => $openapiJson, 'openapi_hash' => hash('sha256', $openapiJson), 'warnings' => '[]',
            'statistics' => json_encode(['endpoints' => count(self::operations($openapi))], JSON_THROW_ON_ERROR),
            'created_at' => gmdate('c'),
        ]);
        return $this->byId((int) $this->pdo->lastInsertId()) ?? [];
    }

    /**
     * Changes a version's note, label or OpenAPI document. Its Markdown source and source hash are kept,
     * so the README baseline is unaffected.
     *
     * @param array<string, mixed>|null $openapi
     */
    public function update(int $id, ?string $label, string $note, ?array $openapi, ?string $openapiJson = null): void
    {
        $fields = ['note = :note'];
        $params = ['id' => $id, 'note' => $note !== '' ? $note : null];
        if ($label !== null && trim($label) !== '') {
            $fields[] = 'label = :label';
            $params['label'] = self::label($label);
            $this->assertLabelFree($params['label'], $id);
        }
        if ($openapi !== null) {
            $openapiJson ??= json_encode($openapi, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $fields[] = 'openapi_json = :openapi_json';
            $fields[] = 'openapi_hash = :openapi_hash';
            $params['openapi_json'] = $openapiJson;
            $params['openapi_hash'] = hash('sha256', $openapiJson);
        }
        $stmt = $this->pdo->prepare('UPDATE versions SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            throw new \OutOfBoundsException('Version not found.');
        }
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM versions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new \OutOfBoundsException('Version not found.');
        }
    }

    /** A trimmed, non-empty label within the length limit; the CLI, the banner and imports share this rule. */
    /**
     * Labels are unique per project regardless of case ("v1.0.0" and "V1.0.0" are the same version),
     * as in the web UI, the hosted backend and the npm package.
     */
    private function assertLabelFree(string $label, int $exceptId = 0): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM versions WHERE lower(label) = lower(:label) AND id <> :id');
        $stmt->execute(['label' => $label, 'id' => $exceptId]);
        if ($stmt->fetchColumn()) {
            throw new \OverflowException("Version '{$label}' already exists.");
        }
    }

    public static function label(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('Version label is required.');
        }
        $length = preg_match('//u', $label) === 1 ? (int) preg_match_all('/./us', $label) : strlen($label);
        if ($length > self::MAX_LABEL_LENGTH) {
            throw new \InvalidArgumentException('Version label is too long (' . self::MAX_LABEL_LENGTH . ' characters maximum).');
        }
        return $label;
    }

    /** The label after the newest one: a patch bump for vX.Y.Z labels, otherwise a "-next" suffix. */
    /** Same rule as the web UI and the npm package (see VersionNumber). */
    public function nextLabel(): string
    {
        $labels = $this->pdo->query('SELECT label FROM versions ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
        return VersionNumber::suggestNext(array_map('strval', $labels)) ?? 'v1.0.0';
    }

    /**
     * Endpoint-level difference between the README baseline and the working README.
     *
     * @return array{endpointsAdded: int, endpointsRemoved: int, endpointsChanged: int}|null
     */
    public function workingCopySummary(): ?array
    {
        $markdown = is_readable($this->readmePath) ? file_get_contents($this->readmePath) : false;
        if ($markdown === false) return null;
        $current = self::operations(\MarkdownOpenApiParser::parse($markdown)['openapi'] ?? []);
        $baseline = [];
        $latest = $this->latest();
        if ($latest !== null) {
            $stmt = $this->pdo->prepare('SELECT openapi_json FROM versions WHERE id = :id');
            $stmt->execute(['id' => $latest['id']]);
            $baseline = self::operations(json_decode((string) $stmt->fetchColumn(), true) ?: []);
        }
        $changed = 0;
        foreach (array_intersect_key($current, $baseline) as $key => $operation) {
            if ($operation !== $baseline[$key]) $changed++;
        }
        return [
            'endpointsAdded' => count(array_diff_key($current, $baseline)),
            'endpointsRemoved' => count(array_diff_key($baseline, $current)),
            'endpointsChanged' => $changed,
        ];
    }

    /** @return array<string, string> "METHOD /path" => encoded operation */
    private static function operations(array $openapi): array
    {
        $operations = [];
        foreach ((array) ($openapi['paths'] ?? []) as $path => $item) {
            foreach ((array) $item as $method => $operation) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'], true)) {
                    $operations[strtoupper($method) . ' ' . $path] = (string) json_encode($operation);
                }
            }
        }
        return $operations;
    }

    /**
     * The README baseline: the newest version committed from the working README.
     * Versions imported or edited in the UI have no source hash and are skipped.
     *
     * @return array<string, mixed>|null
     */
    private function latest(): ?array
    {
        $stmt = $this->pdo->query('SELECT id, label, source_hash FROM versions WHERE source_hash IS NOT NULL ORDER BY id DESC LIMIT 1');
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
