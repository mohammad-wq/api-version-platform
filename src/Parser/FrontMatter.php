<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform\Parser;

/**
 * Reads the optional header of an API document: a small YAML subset between two `---` lines at the very
 * top. No YAML extension is needed.
 *
 *     ---
 *     avp: 1
 *     title: Example Service API
 *     version: 2.1.0
 *     description: One sentence about the API.
 *     auth: bearer
 *     servers:
 *       - https://api.example.com/v1
 *     ---
 *
 * A document is "strict" when this header is present and has an `avp` key. Supported syntax: `key: value`
 * scalars (bare, "double-quoted" JSON strings or 'single-quoted'), `# comments`, and lists written as
 * `- item` lines or inline `[a, b]`.
 */
final class FrontMatter
{
    public const FORMAT_VERSION = 1;
    public const KEYS = ['avp', 'title', 'version', 'description', 'servers', 'auth'];
    public const AUTH_MODES = ['bearer', 'none'];
    /** The closing --- must appear within this many lines, otherwise the header counts as unterminated. */
    private const MAX_LINES = 80;

    /**
     * @param list<string> $lines the document's lines (as from Lexer::split())
     * @return array{present: bool, data: array<string, mixed>, errors: list<array{code: string, message: string, lines: list<int>}>, lines: list<string>}
     *   `lines` is the document with the header lines blanked, so every later line keeps its number.
     */
    public static function extract(array $lines): array
    {
        $none = ['present' => false, 'data' => [], 'errors' => [], 'lines' => $lines];
        if (trim($lines[0] ?? '') !== '---') {
            return $none;
        }
        // A closing --- (or ...) within the first lines ends the header.
        $closing = null;
        for ($i = 1, $limit = min(count($lines), self::MAX_LINES); $i < $limit; $i++) {
            $line = rtrim($lines[$i]);
            if ($line === '---' || $line === '...') {
                $closing = $i;
                break;
            }
        }
        // Without one, the header is the run of header-looking lines at the top (it ends at a blank line).
        $runEnd = $closing ?? 1;
        if ($closing === null) {
            while ($runEnd < count($lines) && preg_match('/^(?:#|[A-Za-z][A-Za-z0-9_-]*\s*:|\s+\S|-\s)/', rtrim($lines[$runEnd]))) {
                $runEnd++;
            }
        }
        $hasAvp = false;
        for ($i = 1; $i < $runEnd; $i++) {
            $hasAvp = $hasAvp || preg_match('/^avp\s*:/', rtrim($lines[$i])) === 1;
        }
        if (!$hasAvp) {
            return $none; // a leading horizontal rule, not a header
        }
        $errors = [];
        // Index of the header's last line: the closing fence, or (unterminated) the last header-looking line.
        $last = $closing ?? ($runEnd - 1);
        if ($closing === null) {
            $errors[] = self::error('FRONT_MATTER_UNTERMINATED', 'The front-matter header starting on line 1 is never closed with a --- line.', [1, $last + 1]);
        }
        $bodyEnd = $closing !== null ? $closing - 1 : $last;
        $data = self::parseBody(array_slice($lines, 1, $bodyEnd), 2, $errors);
        $blank = array_fill(0, $last + 1, '');
        $out = array_merge($blank, array_slice($lines, count($blank)));
        return ['present' => true, 'data' => self::validate($data, $errors), 'errors' => $errors, 'lines' => $out];
    }

    /**
     * @param list<string> $body header lines between the fences
     * @return array<string, array{line: int, value: mixed}>
     */
    private static function parseBody(array $body, int $firstLine, array &$errors): array
    {
        $entries = [];
        $key = null;
        foreach ($body as $offset => $raw) {
            $lineNo = $firstLine + $offset;
            $line = rtrim($raw);
            if ($line === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (preg_match('/^\s*-\s+(.+)$/', $line, $item) || preg_match('/^\s*-$/', $line)) {
                if ($key === null || !is_array($entries[$key]['value'])) {
                    $errors[] = self::error('FRONT_MATTER_SYNTAX', 'A list item has no list key above it.', [$lineNo, $lineNo]);
                    continue;
                }
                $entries[$key]['value'][] = self::scalar(trim($item[1] ?? ''), $lineNo, $errors);
                continue;
            }
            if (!preg_match('/^([A-Za-z][A-Za-z0-9_-]*)\s*:\s*(.*)$/', $line, $match)) {
                $errors[] = self::error('FRONT_MATTER_SYNTAX', 'Expected "key: value" in the front-matter header.', [$lineNo, $lineNo]);
                continue;
            }
            $key = $match[1];
            if (isset($entries[$key])) {
                $errors[] = self::error('FRONT_MATTER_DUPLICATE_KEY', "The front-matter key \"{$key}\" is set more than once.", [$lineNo, $lineNo]);
            }
            $value = self::stripComment(trim($match[2]));
            if ($value === '') {
                $entries[$key] = ['line' => $lineNo, 'value' => []]; // a list follows (or the value is empty)
            } elseif ($value[0] === '[') {
                $entries[$key] = ['line' => $lineNo, 'value' => self::inlineList($value, $lineNo, $errors)];
            } else {
                $entries[$key] = ['line' => $lineNo, 'value' => self::scalar($value, $lineNo, $errors)];
            }
        }
        return $entries;
    }

    /**
     * @param array<string, array{line: int, value: mixed}> $entries
     * @return array<string, mixed>
     */
    private static function validate(array $entries, array &$errors): array
    {
        $data = [];
        foreach ($entries as $key => $entry) {
            $at = [$entry['line'], $entry['line']];
            $value = $entry['value'];
            if (!in_array($key, self::KEYS, true)) {
                $errors[] = self::error('FRONT_MATTER_UNKNOWN_KEY', "Unknown front-matter key \"{$key}\". Supported keys: " . implode(', ', self::KEYS) . '.', $at);
                continue;
            }
            if ($key === 'servers') {
                if (!is_array($value)) {
                    $errors[] = self::error('FRONT_MATTER_INVALID_VALUE', '"servers" must be a list of URLs.', $at);
                    continue;
                }
                $data['servers'] = array_values($value);
                $data['servers_line'] = $entry['line'];
                continue;
            }
            if (is_array($value)) {
                $errors[] = self::error('FRONT_MATTER_INVALID_VALUE', "\"{$key}\" must be a single value, not a list.", $at);
                continue;
            }
            $data[$key] = $value;
            $data[$key . '_line'] = $entry['line'];
        }
        if (!array_key_exists('avp', $data) || (string) $data['avp'] !== (string) self::FORMAT_VERSION) {
            $errors[] = self::error('FRONT_MATTER_UNSUPPORTED_VERSION', 'This tool reads "avp: ' . self::FORMAT_VERSION . '" documents; got "avp: ' . (string) ($data['avp'] ?? '') . '".', [$data['avp_line'] ?? 1, $data['avp_line'] ?? 1]);
        }
        $title = $data['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            $errors[] = self::error('FRONT_MATTER_MISSING_TITLE', 'The front-matter header needs a non-empty "title:".', [$data['title_line'] ?? 1, $data['title_line'] ?? 1]);
        } elseif (mb_strlen($title) > 200) {
            $errors[] = self::error('FRONT_MATTER_INVALID_VALUE', '"title" is too long (200 characters maximum).', [$data['title_line'], $data['title_line']]);
        }
        if (isset($data['version']) && !preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,49}$/', (string) $data['version'])) {
            $errors[] = self::error('FRONT_MATTER_INVALID_VALUE', '"version" must be 1-50 characters such as 1.0.0 or 2024-05.', [$data['version_line'], $data['version_line']]);
        }
        if (isset($data['auth']) && !in_array($data['auth'], self::AUTH_MODES, true)) {
            $errors[] = self::error('FRONT_MATTER_INVALID_VALUE', '"auth" must be one of: ' . implode(', ', self::AUTH_MODES) . '.', [$data['auth_line'], $data['auth_line']]);
        }
        return $data;
    }

    private static function scalar(string $value, int $line, array &$errors): string
    {
        $value = self::stripComment($value);
        if ($value !== '' && $value[0] === '"') {
            $decoded = json_decode($value);
            if (!is_string($decoded)) {
                $errors[] = self::error('FRONT_MATTER_SYNTAX', 'A double-quoted value is not a valid quoted string.', [$line, $line]);
                return trim($value, '"');
            }
            return $decoded;
        }
        if ($value !== '' && $value[0] === "'") {
            if (strlen($value) < 2 || !str_ends_with($value, "'")) {
                $errors[] = self::error('FRONT_MATTER_SYNTAX', 'A single-quoted value is not closed.', [$line, $line]);
                return ltrim($value, "'");
            }
            return str_replace("''", "'", substr($value, 1, -1));
        }
        return $value;
    }

    /** @return list<string> */
    private static function inlineList(string $value, int $line, array &$errors): array
    {
        if (!str_ends_with($value, ']')) {
            $errors[] = self::error('FRONT_MATTER_SYNTAX', 'An inline list must end with ].', [$line, $line]);
            return [];
        }
        $inner = trim(substr($value, 1, -1));
        if ($inner === '') {
            return [];
        }
        $items = [];
        foreach (preg_split('/\s*,\s*/', $inner) as $item) {
            $items[] = self::scalar(trim($item), $line, $errors);
        }
        return $items;
    }

    /** Drops a trailing ` # comment` that is not inside quotes. */
    private static function stripComment(string $value): string
    {
        if ($value === '' || $value[0] === '"' || $value[0] === "'") {
            if ($value !== '' && preg_match('/^("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\')\s*(?:#.*)?$/', $value, $m)) {
                return $m[1];
            }
            return $value;
        }
        return rtrim((string) preg_replace('/\s+#.*$/', '', $value));
    }

    /** @return array{code: string, message: string, lines: list<int>} */
    private static function error(string $code, string $message, array $lines): array
    {
        return ['code' => $code, 'message' => $message, 'lines' => array_values($lines)];
    }
}
