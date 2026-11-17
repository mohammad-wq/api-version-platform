<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform;

/**
 * Plain JSON files in the state folder (default `.avp/`), so no database extension is needed:
 *
 *   versions.json         light index of every version (no Markdown or OpenAPI payload)
 *   versions/<id>.json    that version's Markdown source and OpenAPI document
 *   history.json          the Test APIs request history (newest 200 kept)
 *   meta.json             project name, description, environments, ...
 *
 * Writes are atomic (temp file + rename) and every read-modify-write runs under an exclusive file lock,
 * so the CLI and the web server can write at the same time.
 *
 * A `.avp/storage.sqlite` left by an older release is imported once when PHP has pdo_sqlite.
 */
final class Storage
{
    private const HISTORY_LIMIT = 200;
    private const LOCK_WAIT_SECONDS = 5;

    private string $dir;
    /** @var resource|null */
    private $lock = null;
    private int $lockDepth = 0;

    /** @param string $path The state folder, or (for old configs) a file inside it such as `.avp/storage.sqlite` */
    public function __construct(string $path)
    {
        // An old-style path to a SQLite file: the default `.avp/storage.sqlite` keeps its folder; any other file gets
        // its own folder next to it (`data/my.sqlite` -> `data/my/`), so a shared parent folder is never filled with our files.
        $legacy = preg_match('/\.(sqlite3?|db)$/i', $path) === 1 ? $path : null;
        if ($legacy === null) {
            $this->dir = rtrim($path, '/\\');
        } else {
            $this->dir = strtolower(basename($legacy)) === 'storage.sqlite' ? dirname($legacy) : (string) preg_replace('/\.(sqlite3?|db)$/i', '', $legacy);
        }
        if (!is_dir($this->dir)) {
            if (file_exists($this->dir) || !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
                throw new \RuntimeException("Could not create storage directory: {$this->dir}");
            }
            self::ignoreInGit($this->dir);
        }
        if (!is_dir($this->dir . '/versions') && !@mkdir($this->dir . '/versions', 0775, true) && !is_dir($this->dir . '/versions')) {
            throw new \RuntimeException("Could not create storage directory: {$this->dir}/versions");
        }
        $this->importSqlite($legacy ?? $this->dir . '/storage.sqlite');
    }

    /**
     * Makes a state directory the package created ignore itself, so users never have to edit .gitignore.
     * Only called for directories this package creates — never for an existing folder such as the project root.
     */
    public static function ignoreInGit(string $directory): void
    {
        $file = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '.gitignore';
        if (!file_exists($file)) {
            @file_put_contents($file, "# Local API Version Platform state (created automatically).\n*\n");
        }
    }

    public function directory(): string
    {
        return $this->dir;
    }

    /** Runs $fn holding the exclusive lock; nested calls reuse it. */
    public function locked(callable $fn): mixed
    {
        if ($this->lockDepth === 0) {
            $handle = @fopen($this->dir . '/.lock', 'c');
            if ($handle === false) throw new \RuntimeException("Could not open the lock file in {$this->dir}");
            $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) > $deadline) {
                    fclose($handle);
                    throw new \RuntimeException('The version store is busy (locked by another process). Try again.');
                }
                usleep(20_000);
            }
            $this->lock = $handle;
        }
        $this->lockDepth++;
        try {
            return $fn();
        } finally {
            if (--$this->lockDepth === 0 && $this->lock !== null) {
                flock($this->lock, LOCK_UN);
                fclose($this->lock);
                $this->lock = null;
            }
        }
    }

    // Versions

    /** @return list<array<string, mixed>> Light rows (no markdown / openapi_json), oldest first */
    public function versions(): array
    {
        return $this->readIndex()['versions'];
    }

    /** @return array<string, mixed>|null The light row plus `markdown` and `openapi_json` */
    public function version(int $id): ?array
    {
        foreach ($this->versions() as $row) {
            if ((int) $row['id'] === $id) {
                $blob = $this->readJson($this->blobPath($id)) ?? [];
                return $row + ['markdown' => $blob['markdown'] ?? null, 'openapi_json' => $blob['openapi_json'] ?? '{}'];
            }
        }
        return null;
    }

    /** @return string The stored OpenAPI document as JSON text ('{}' when unknown) */
    public function openapiJson(int $id): string
    {
        return (string) (($this->readJson($this->blobPath($id)) ?? [])['openapi_json'] ?? '{}');
    }

    /**
     * @param array<string, mixed> $row Light fields: label, note, source_hash, openapi_hash, warnings, statistics, created_at
     * @return int The new version's id
     */
    public function addVersion(array $row, ?string $markdown, string $openapiJson): int
    {
        return $this->locked(function () use ($row, $markdown, $openapiJson): int {
            $index = $this->readIndex();
            $id = $index['next_id'];
            $this->writeJson($this->blobPath($id), ['markdown' => $markdown, 'openapi_json' => $openapiJson]);
            $index['versions'][] = ['id' => $id] + $row;
            $index['next_id'] = $id + 1;
            $this->writeIndex($index);
            return $id;
        });
    }

    /**
     * @param array<string, mixed> $fields Light fields to replace
     * @return bool false when the version does not exist
     */
    public function updateVersion(int $id, array $fields, ?string $openapiJson = null): bool
    {
        return $this->locked(function () use ($id, $fields, $openapiJson): bool {
            $index = $this->readIndex();
            foreach ($index['versions'] as &$row) {
                if ((int) $row['id'] !== $id) continue;
                if ($openapiJson !== null) {
                    $blob = $this->readJson($this->blobPath($id)) ?? ['markdown' => null];
                    $blob['openapi_json'] = $openapiJson;
                    $this->writeJson($this->blobPath($id), $blob);
                }
                $row = array_replace($row, $fields);
                $this->writeIndex($index);
                return true;
            }
            return false;
        });
    }

    public function deleteVersion(int $id): bool
    {
        return $this->locked(function () use ($id): bool {
            $index = $this->readIndex();
            $kept = array_values(array_filter($index['versions'], static fn (array $row): bool => (int) $row['id'] !== $id));
            if (count($kept) === count($index['versions'])) return false;
            $index['versions'] = $kept;
            $this->writeIndex($index);
            @unlink($this->blobPath($id));
            return true;
        });
    }

    // Request history

    /** @param array<string, mixed> $entry */
    public function addHistory(array $entry): void
    {
        $this->locked(function () use ($entry): void {
            $data = $this->readJson($this->dir . '/history.json') ?? [];
            $rows = is_array($data['entries'] ?? null) ? $data['entries'] : [];
            $id = (int) ($data['next_id'] ?? 1);
            $rows[] = ['id' => $id] + $entry;
            $this->writeJson($this->dir . '/history.json', ['next_id' => $id + 1, 'entries' => array_slice($rows, -self::HISTORY_LIMIT)]);
        });
    }

    /** @return list<array<string, mixed>> Newest first */
    public function history(int $limit): array
    {
        $rows = ($this->readJson($this->dir . '/history.json') ?? [])['entries'] ?? [];
        return array_slice(array_reverse(is_array($rows) ? $rows : []), 0, max(1, $limit));
    }

    public function clearHistory(): void
    {
        $this->locked(function (): void {
            $data = $this->readJson($this->dir . '/history.json') ?? [];
            $this->writeJson($this->dir . '/history.json', ['next_id' => (int) ($data['next_id'] ?? 1), 'entries' => []]);
        });
    }

    // Project metadata

    public function meta(string $key): ?string
    {
        $value = ($this->readJson($this->dir . '/meta.json') ?? [])[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setMeta(string $key, string $value): void
    {
        $this->locked(function () use ($key, $value): void {
            $meta = $this->readJson($this->dir . '/meta.json') ?? [];
            $meta[$key] = $value;
            $this->writeJson($this->dir . '/meta.json', $meta);
        });
    }

    // Files

    private function blobPath(int $id): string
    {
        return "{$this->dir}/versions/{$id}.json";
    }

    /** @return array{next_id: int, versions: list<array<string, mixed>>} */
    private function readIndex(): array
    {
        $data = $this->readJson($this->dir . '/versions.json') ?? [];
        $versions = is_array($data['versions'] ?? null) ? array_values($data['versions']) : [];
        $max = 0;
        foreach ($versions as $row) $max = max($max, (int) ($row['id'] ?? 0));
        return ['next_id' => max((int) ($data['next_id'] ?? 1), $max + 1), 'versions' => $versions];
    }

    /** @param array{next_id: int, versions: list<array<string, mixed>>} $index */
    private function writeIndex(array $index): void
    {
        $this->writeJson($this->dir . '/versions.json', $index);
    }

    /** @return array<string, mixed>|null null when the file is missing or unreadable */
    private function readJson(string $file): ?array
    {
        if (!is_file($file)) return null;
        // Another process may be replacing the file at this instant (Windows refuses reads then): retry, never mistake it for "empty".
        $text = false;
        for ($attempt = 0; $attempt < 25 && ($text = @file_get_contents($file)) === false; $attempt++) {
            usleep(20_000);
        }
        if ($text === false) throw new \RuntimeException("Could not read {$file}");
        if ($text === '') return null;
        $data = json_decode($text, true);
        if (!is_array($data)) throw new \RuntimeException("{$file} is not valid JSON. Restore it from a backup, or delete it to start a fresh index (each version's data stays in {$this->dir}/versions).");
        return $data;
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $file, array $data): void
    {
        $text = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, $text) === false) throw new \RuntimeException("Could not write {$file}");
        for ($attempt = 0; !@rename($temp, $file); $attempt++) {
            if ($attempt >= 25) {
                @unlink($temp);
                throw new \RuntimeException("Could not replace {$file} (is another program holding it open?)");
            }
            usleep(20_000); // Windows refuses to replace a file another process is reading at that instant
        }
    }

    // One-time import from the SQLite store of earlier releases

    private function importSqlite(string $sqlite): void
    {
        if (!is_file($sqlite) || is_file($this->dir . '/versions.json') || !extension_loaded('pdo_sqlite')) return;
        $this->locked(function () use ($sqlite): void {
            if (is_file($this->dir . '/versions.json')) return;
            $pdo = new \PDO('sqlite:' . $sqlite, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN);
            if (in_array('versions', $tables, true)) {
                foreach ($pdo->query('SELECT * FROM versions ORDER BY id ASC') as $row) {
                    $index = $this->readIndex();
                    $this->writeJson($this->blobPath((int) $row['id']), ['markdown' => $row['markdown'], 'openapi_json' => $row['openapi_json']]);
                    $index['versions'][] = [
                        'id' => (int) $row['id'], 'label' => $row['label'], 'note' => $row['note'],
                        'source_hash' => $row['source_hash'], 'openapi_hash' => $row['openapi_hash'],
                        'warnings' => json_decode((string) $row['parser_warnings_json'], true) ?: [],
                        'statistics' => json_decode((string) $row['parser_statistics_json'], true) ?: [],
                        'created_at' => $row['created_at'],
                    ];
                    $index['next_id'] = max($index['next_id'], (int) $row['id'] + 1);
                    $this->writeIndex($index);
                }
            }
            if (!is_file($this->dir . '/versions.json')) $this->writeIndex(['next_id' => 1, 'versions' => []]);
            if (in_array('metadata', $tables, true)) {
                $meta = [];
                foreach ($pdo->query('SELECT key, value FROM metadata') as $row) {
                    if ($row['key'] !== 'schema_version') $meta[$row['key']] = (string) $row['value'];
                }
                if ($meta !== []) $this->writeJson($this->dir . '/meta.json', $meta);
            }
            if (in_array('request_history', $tables, true)) {
                $entries = array_reverse($pdo->query('SELECT * FROM request_history ORDER BY id DESC LIMIT ' . self::HISTORY_LIMIT)->fetchAll(\PDO::FETCH_ASSOC));
                $next = $entries === [] ? 1 : (int) end($entries)['id'] + 1;
                $this->writeJson($this->dir . '/history.json', ['next_id' => $next, 'entries' => $entries]);
            }
        });
    }
}
