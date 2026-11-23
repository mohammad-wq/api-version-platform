<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform;

final class VersionService
{
    public const MAX_LABEL_LENGTH = 50;

    public function __construct(private string $readmePath, private Storage $storage)
    {
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
        $parse = MarkdownOpenApiParser::parse($markdown);
        if (($parse['statistics']['endpoints'] ?? 0) === 0) {
            return ['state' => 'invalid', 'readme_path' => $this->readmePath, 'message' => "No API endpoints were found in {$this->readmePath}. See `avp template` for the format.", 'warnings' => $parse['warnings']];
        }
        $sourceHash = SourceHasher::hash($markdown);
        $latest = $this->latest();
        if ($latest === null) {
            return ['state' => 'uninitialized', 'readme_path' => $this->readmePath, 'source_hash' => $sourceHash, 'warnings' => $parse['warnings']];
        }
        return [
            'state' => hash_equals($latest['source_hash'], $sourceHash) ? 'clean' : 'modified',
            'readme_path' => $this->readmePath,
            'source_hash' => $sourceHash,
            'latest_version' => $latest['label'],
            'latest_source_hash' => $latest['source_hash'],
            'warnings' => $parse['warnings'],
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
        $parse = MarkdownOpenApiParser::parse($markdown);
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
        $id = $this->storage->locked(function () use ($label, $note, $source, $sourceHash, $openapiJson, $openapiHash, $parse, $createdAt): int {
            $this->assertLabelFree($label);
            return $this->storage->addVersion([
                'label' => $label, 'note' => $note !== '' ? $note : null, 'source_hash' => $sourceHash, 'openapi_hash' => $openapiHash,
                'warnings' => $parse['warnings'], 'statistics' => $parse['statistics'], 'created_at' => $createdAt,
            ], $source, $openapiJson);
        });
        return ['id' => $id, 'label' => $label, 'source_hash' => $sourceHash, 'openapi_hash' => $openapiHash, 'created_at' => $createdAt, 'parse' => ['warnings' => $parse['warnings'], 'statistics' => $parse['statistics']]];
    }

    /** @return list<array<string, mixed>> Newest first */
    public function all(): array
    {
        return array_map(static fn (array $row): array => self::light($row), array_reverse($this->storage->versions()));
    }

    /** @return array<string, mixed>|null */
    public function get(string $label): ?array
    {
        foreach ($this->storage->versions() as $row) {
            if ($row['label'] === $label) return $this->byId((int) $row['id']);
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    public function byId(int $id): ?array
    {
        $row = $this->storage->version($id);
        if ($row === null) return null;
        // Objects, not arrays: an array decode turns every empty object `{}` into `[]` when served.
        $row['openapi'] = json_decode($row['openapi_json']);
        unset($row['openapi_json']);
        return $row;
    }

    /** The newest version of any kind; the UI treats it as "latest". */
    public function newestId(): ?int
    {
        $versions = $this->storage->versions();
        return $versions === [] ? null : (int) end($versions)['id'];
    }

    /** The version list fields (no Markdown or OpenAPI payload). @return array<string, mixed> */
    private static function light(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'label' => $row['label'], 'note' => $row['note'] ?? null,
            'source_hash' => $row['source_hash'] ?? null, 'openapi_hash' => $row['openapi_hash'],
            'warnings' => $row['warnings'] ?? [], 'statistics' => $row['statistics'] ?? null, 'created_at' => $row['created_at'],
        ];
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
        $id = $this->storage->locked(function () use ($label, $note, $openapi, $openapiJson): int {
            $this->assertLabelFree($label);
            return $this->storage->addVersion([
                'label' => $label, 'note' => $note !== '' ? $note : null, 'source_hash' => null, 'openapi_hash' => hash('sha256', $openapiJson),
                'warnings' => [], 'statistics' => ['endpoints' => count(self::operations($openapi))], 'created_at' => gmdate('c'),
            ], null, $openapiJson);
        });
        return $this->byId($id) ?? [];
    }

    /**
     * Changes a version's note, label or OpenAPI document. Its Markdown source and source hash are kept,
     * so the README baseline is unaffected.
     *
     * @param array<string, mixed>|null $openapi
     */
    public function update(int $id, ?string $label, string $note, ?array $openapi, ?string $openapiJson = null): void
    {
        $fields = ['note' => $note !== '' ? $note : null];
        if ($label !== null && trim($label) !== '') {
            $fields['label'] = self::label($label);
        }
        $json = null;
        if ($openapi !== null) {
            $json = $openapiJson ?? json_encode($openapi, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $fields['openapi_hash'] = hash('sha256', $json);
        }
        $this->storage->locked(function () use ($id, $fields, $json): void {
            if (isset($fields['label'])) $this->assertLabelFree($fields['label'], $id);
            if (!$this->storage->updateVersion($id, $fields, $json)) {
                throw new \OutOfBoundsException('Version not found.');
            }
        });
    }

    public function delete(int $id): void
    {
        if (!$this->storage->deleteVersion($id)) {
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
        foreach ($this->storage->versions() as $row) {
            if ((int) $row['id'] !== $exceptId && strtolower((string) $row['label']) === strtolower($label)) {
                throw new \OverflowException("Version '{$label}' already exists.");
            }
        }
    }

    public static function label(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('Version label is required.');
        }
        // Control, format and unassigned characters (newlines, BEL, byte-order marks, invalid UTF-8) break `avp version list`
        // output and render as boxes in the UI. Letters, digits, spaces, dots, slashes and emoji are all fine.
        if (preg_match('//u', $label) !== 1 || preg_match('/\p{C}/u', $label) === 1) {
            throw new \InvalidArgumentException('Version label contains invalid characters.');
        }
        $length = (int) preg_match_all('/./us', $label);
        if ($length > self::MAX_LABEL_LENGTH) {
            throw new \InvalidArgumentException('Version label is too long (' . self::MAX_LABEL_LENGTH . ' characters maximum).');
        }
        return $label;
    }

    /** The label after the newest one: a patch bump for vX.Y.Z labels, otherwise a "-next" suffix. */
    /** Same rule as the web UI and the npm package (see VersionNumber). */
    public function nextLabel(): string
    {
        $labels = array_map(static fn (array $row): string => (string) $row['label'], $this->storage->versions());
        return VersionNumber::suggestNext($labels) ?? 'v1.0.0';
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
        $current = self::operations(MarkdownOpenApiParser::parse($markdown)['openapi'] ?? []);
        $baseline = [];
        $latest = $this->latest();
        if ($latest !== null) {
            $baseline = self::operations(json_decode($this->storage->openapiJson((int) $latest['id']), true) ?: []);
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
        foreach (array_reverse($this->storage->versions()) as $row) {
            if (($row['source_hash'] ?? null) !== null) return ['id' => (int) $row['id'], 'label' => $row['label'], 'source_hash' => $row['source_hash']];
        }
        return null;
    }
}
