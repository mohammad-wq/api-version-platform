<?php

/**
 * Converts the API README template format into an OpenAPI 3.0.3 document.
 */
final class MarkdownOpenApiParser
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private array $warnings = [];
    private array $endpoints = [];
    private array $servers = [];
    private array $paginationParameters = [];
    private array $publicTargets = [];
    private array $sectionsForTag = [];
    private ?array $responseEnvelope = null;
    private ?array $listEnvelope = null;
    private string $title = 'Untitled API';
    private string $description = '';

    public static function parse(string $markdown): array
    {
        return (new self())->convert($markdown);
    }

    private function convert(string $markdown): array
    {
        $notUtf8 = preg_match('//u', $markdown) !== 1;
        if ($notUtf8) {
            // OpenAPI is JSON, which must be UTF-8. Read the file as Windows-1252 ("ANSI" in Windows editors).
            $converted = function_exists('mb_convert_encoding') ? @mb_convert_encoding($markdown, 'UTF-8', 'Windows-1252') : null;
            $markdown = is_string($converted) && preg_match('//u', $converted) === 1
                ? $converted
                : (string) preg_replace_callback('/[\x80-\xFF]/', static fn(array $m): string => chr(0xC0 | (ord($m[0]) >> 6)) . chr(0x80 | (ord($m[0]) & 0x3F)), $markdown);
        }
        $lines = preg_split('/\r\n|\n|\r/', preg_replace('/^\xEF\xBB\xBF/', '', $markdown));
        if ($notUtf8) {
            $this->warnings[] = [
                'code' => 'NOT_UTF8',
                'message' => 'The document is not valid UTF-8, so it was read as Windows-1252. Save it as UTF-8 to be sure every character is kept.',
                'lines' => [1, 1],
            ];
        }
        $blocks = $this->readBlocks($this->hideHtmlComments($lines));
        $sections = $this->buildSections($blocks);
        $this->sectionsForTag = $sections;
        $this->readConventions($sections);
        $this->readTables($sections);
        $this->readEndpointHeadings($sections);
        $this->bindEndpointDetails($sections);
        $this->attachTableNotes($sections);
        $this->applyPublicTargets();

        if (!$this->endpoints) {
            $this->warnings[] = [
                'code' => 'NO_ENDPOINTS_FOUND',
                'message' => 'No API endpoints were detected. Check the Markdown table headers and endpoint paths.',
                'lines' => [1, max(1, count($lines))],
            ];
        }

        $paths = [];
        $methods = [];
        $tags = [];
        $usedOperationIds = [];
        foreach ($this->endpoints as $endpoint) {
            $operation = $this->toOperation($endpoint);
            $operation['operationId'] = $this->operationId($endpoint['method'], $endpoint['path'], $usedOperationIds);
            $method = strtolower($endpoint['method']);
            if (!isset($paths[$endpoint['path']]) && preg_match('#(^|/)\.{1,2}(/|$)#', $endpoint['path'])) {
                $this->warn(
                    'DOT_SEGMENT_PATH',
                    "{$endpoint['path']} contains a \".\" or \"..\" segment; clients may resolve it to a different URL.",
                    [$endpoint['line'] ?? 1, $endpoint['line'] ?? 1],
                    $endpoint,
                );
            }
            // OpenAPI keys operations by path+method, so a repeated table row would silently
            // overwrite the earlier one here and lose its detail. Say so instead.
            if (isset($paths[$endpoint['path']][$method])) {
                $this->warn(
                    'DUPLICATE_ENDPOINT',
                    "{$endpoint['method']} {$endpoint['path']} is documented more than once; the last row wins.",
                    [$endpoint['line'] ?? 1, $endpoint['line'] ?? 1],
                    $endpoint,
                );
            }
            $paths[$endpoint['path']][$method] = $operation;
            $methods[$endpoint['method']] = ($methods[$endpoint['method']] ?? 0) + 1;
            if ($endpoint['tag'] !== '') {
                $tags[$endpoint['tag']] = true;
            }
        }

        $openapi = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $this->title,
                'version' => '1.0.0',
            ],
            'paths' => (object)$paths,
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                    ],
                ],
            ],
        ];
        if ($this->description !== '') {
            $openapi['info']['description'] = $this->description;
        }
        if ($this->servers) {
            $openapi['servers'] = array_values($this->servers);
        }
        if ($tags) {
            $openapi['tags'] = array_map(
                static fn(string $name): array => ['name' => $name],
                array_keys($tags)
            );
        }

        return [
            'openapi' => $openapi,
            'warnings' => $this->warnings,
            'statistics' => [
                'endpoints' => count($this->endpoints),
                'methods' => $methods,
                'tags' => count($tags),
            ],
        ];
    }

    private function hideHtmlComments(array $lines): array
    {
        $hidden = false;
        foreach ($lines as &$line) {
            $output = '';
            $quoted = false;
            while ($line !== '') {
                if ($hidden) {
                    $end = strpos($line, '-->');
                    if ($end === false) {
                        $line = '';
                        continue;
                    }
                    $line = substr($line, $end + 3);
                    $hidden = false;
                    continue;
                }
                $start = strpos($line, '<!--');
                if ($start === false) {
                    $output .= $line;
                    $line = '';
                    continue;
                }
                $output .= substr($line, 0, $start);
                $line = substr($line, $start + 4);
                $end = strpos($line, '-->');
                if ($end === false) {
                    $hidden = true;
                    $line = '';
                } else {
                    $line = substr($line, $end + 3);
                }
            }
            $line = $output;
        }
        unset($line);
        return $lines;
    }

    private function readBlocks(array $lines): array
    {
        $blocks = [];
        $count = count($lines);
        for ($i = 0; $i < $count;) {
            $trimmed = trim($lines[$i]);
            if ($trimmed === '') {
                $i++;
                continue;
            }
            // A horizontal rule is structural, not content: it must never be captured as
            // endpoint text (it would otherwise surface as summaries like "---").
            if (preg_match('/^(?:-{3,}|\*{3,}|_{3,})\s*$/', $trimmed)) {
                $i++;
                continue;
            }
            if (preg_match('/^(```+|~~~+)\s*([\w-]*)/', $trimmed, $fence)) {
                $start = $i + 1;
                $content = [];
                $marker = $fence[1];
                $i++;
                while ($i < $count && !str_starts_with(trim($lines[$i]), $marker)) {
                    $content[] = $lines[$i++];
                }
                $blocks[] = [
                    'kind' => 'fence',
                    'lang' => strtolower($fence[2] ?? ''),
                    'text' => implode("\n", $content),
                    'lines' => [$start, min($i + 1, $count)],
                ];
                $i++;
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $trimmed, $heading)) {
                $blocks[] = [
                    'kind' => 'heading',
                    'level' => strlen($heading[1]),
                    'text' => trim($heading[2]),
                    'lines' => [$i + 1, $i + 1],
                ];
                $i++;
                continue;
            }
            if (str_starts_with($trimmed, '|') && isset($lines[$i + 1]) && preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/', $lines[$i + 1])) {
                $start = $i + 1;
                $header = $this->splitTableRow($trimmed);
                $rows = [];
                $i += 2;
                while ($i < $count && str_starts_with(trim($lines[$i]), '|')) {
                    $cells = $this->splitTableRow(trim($lines[$i]));
                    while (count($cells) < count($header)) {
                        $cells[] = '';
                    }
                    $rows[] = ['cells' => $cells, 'line' => $i + 1];
                    $i++;
                }
                $blocks[] = [
                    'kind' => 'table',
                    'header' => $header,
                    'rows' => $rows,
                    'lines' => [$start, max($start, $i)],
                ];
                continue;
            }

            $start = $i + 1;
            $paragraph = [];             while ($i < $count) {
                $current = trim($lines[$i]);
                if ($current === '' ||
                    preg_match('/^(?:#{1,6}\s+|```+|~~~+)/', $current) ||
                    // A rule ends the paragraph ("Title text\n---" is not one sentence with "---").
                    preg_match('/^(?:-{3,}|\*{3,}|_{3,})\s*$/', $current) ||
                    (str_starts_with($current, '|') && isset($lines[$i + 1]) && preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/', $lines[$i + 1]))) {
                    break;
                }
                $paragraph[] = $current;
                $i++;
            }
            if (!$paragraph) {
                $i++;
                continue;
            }
            $blocks[] = [
                'kind' => 'paragraph',
                'text' => implode("\n", $paragraph),
                'lines' => [$start, $i],
            ];
        }
        return $blocks;
    }

    private function splitTableRow(string $line): array
    {
        $cells = [];
        $cell = '';
        $escaped = false;
        foreach (str_split($line) as $character) {
            if ($escaped) {
                $cell .= $character;
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === '|') {
                $cells[] = trim($cell);
                $cell = '';
            } else {
                $cell .= $character;
            }
        }
        $cells[] = trim($cell);
        if (str_starts_with(trim($line), '|')) {
            array_shift($cells);
        }
        if (str_ends_with(trim($line), '|')) {
            array_pop($cells);
        }
        return $cells;
    }

    private function buildSections(array $blocks): array
    {
        $sections = [];
        $stack = [];
        foreach ($blocks as $block) {
            if ($block['kind'] === 'heading') {
                while ($stack && end($stack)['level'] >= $block['level']) {
                    array_pop($stack);
                }
                $parent = $stack ? end($stack)['id'] : null;
                $section = [
                    'id' => count($sections),
                    'parent' => $parent,
                    'level' => $block['level'],
                    'title' => $this->cleanTitle($block['text']),
                    'heading' => $block,
                    'prefix' => $this->prefixFromHeading($block['text']),
                    'blocks' => [],
                ];
                $sections[] = $section;
                $stack[] = $section;
                if ($block['level'] === 1 && $this->title === 'Untitled API') {
                    $this->title = $section['title'] ?: $this->title;
                }
                continue;
            }
            if (!$stack) {
                $sections[] = [
                    'id' => count($sections),
                    'parent' => null,
                    'level' => 0,
                    'title' => '',
                    'heading' => null,
                    'prefix' => null,
                    'blocks' => [$block],
                ];
            } else {
                $index = end($stack)['id'];
                $sections[$index]['blocks'][] = $block;
                $stack[count($stack) - 1]['blocks'][] = $block;
            }
        }

        foreach ($sections as $index => &$section) {
            foreach ($section['blocks'] as $block) {
                if ($block['kind'] === 'paragraph' && preg_match('/^Base path:\s*`([^`]+)`/mi', $block['text'], $match)) {
                    $section['prefix'] = trim($match[1], '/');
                    break;
                }
                if ($section['level'] === 1 && $block['kind'] === 'paragraph' && $this->description === '') {
                    $this->description = $this->stripInline($block['text']);
                    break;
                }
            }
            if ($section['prefix'] === null && $section['parent'] !== null) {
                $section['prefix'] = $sections[$section['parent']]['prefix'];
            }
            $section['index'] = $index;
        }
        unset($section);
        return $sections;
    }

    private function cleanTitle(string $title): string
    {
        $title = preg_replace('/\s*—\s*`[^`]*`.*$/u', '', $title);
        $title = preg_replace('/`[^`]*`|\([^)]*\)/', '', $title);
        return trim($this->stripInline($title));
    }

    private function prefixFromHeading(string $heading): ?string
    {
        if (preg_match('/^(?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\b/i', trim($heading))) {
            return null;
        }
        preg_match_all('/`([^`]+)`/', $heading, $matches);
        $prefixes = [];
        foreach ($matches[1] as $value) {
            $value = trim($value, '/');
            if ($value !== '' && !preg_match('/\.[a-z0-9]+$/i', $value)) {
                $prefixes[] = $value;
            }
        }
        if (count($prefixes) === 1) {
            return $prefixes[0];
        }
        if (count($prefixes) > 1) {
            $parts = array_map(static fn(string $prefix): array => explode('/', $prefix), $prefixes);
            $common = [];
            for ($i = 0; isset($parts[0][$i]); $i++) {
                $candidate = $parts[0][$i];
                foreach ($parts as $part) {
                    if (($part[$i] ?? null) !== $candidate) {
                        break 2;
                    }
                }
                $common[] = $candidate;
            }
            if ($common) {
                return implode('/', $common);
            }
        }
        return null;
    }

    private function readConventions(array $sections): void
    {
        foreach ($sections as $section) {
            foreach ($section['blocks'] as $index => $block) {
                if ($block['kind'] === 'fence' && ($block['lang'] === '' || $block['lang'] === 'json')) {
                    $value = $this->decodeJsonExample($block['text']);
                    if (!is_array($value)) {
                        continue;
                    }
                    if ($this->responseEnvelope === null && array_key_exists('statusCode', $value) && array_key_exists('data', $value)) {
                        $this->responseEnvelope = $value;
                    }
                    if ($this->listEnvelope === null && array_key_exists('items', $value) && array_key_exists('pagination', $value)) {
                        $this->listEnvelope = $value;
                    }
                }
                if ($block['kind'] !== 'paragraph') {
                    continue;
                }
                if (preg_match('/^Accepts\s+(.+)/i', $block['text'], $match)) {
                    $this->paginationParameters = $this->parseMentionedQueryParams($match[1], $block['lines']);
                }
                if (preg_match('/Every endpoint except\s+(.+?)\s+requires\b/i', $block['text'], $match)) {
                    preg_match_all('/`([^`]+)`/', $match[1], $public);
                    $this->publicTargets = array_merge($this->publicTargets, $public[1]);
                }
                if (preg_match('/^Environments?:\s*(.+)$/im', $block['text'], $environmentLine)) {
                    $this->readEnvironmentLine($environmentLine[1], $block['lines']);
                }
                if (preg_match('/^Servers?:\s*(?:`([^`\s]+)`|(\S+))\s*$/im', $block['text'], $serverLine)) {
                    // preg_match() sets an unmatched group to '' (not null), so `??` cannot pick the unquoted form.
                    $url = trim(($serverLine[1] ?? '') !== '' ? $serverLine[1] : ($serverLine[2] ?? ''), '`');
                    $canonical = $this->normalizeServerUrl($url);
                    if ($canonical === null) {
                        $this->warn('INVALID_SERVER', "Invalid server URL \"{$url}\"; use an HTTP(S) URL without credentials, query, or fragment.", $block['lines']);
                    } elseif (!in_array($canonical, array_column($this->servers, 'url'), true)) {
                        $this->servers[] = ['url' => $canonical];
                    }
                }
            }
        }
    }

    /** One `Environments:` entry → one server, carrying the UI's login conventions as x-login-*. */
    private function readEnvironmentLine(string $line, array $blockLines): void
    {
        foreach (explode(';', $line) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $settingErrors = false;
            if (!preg_match('/^([^=]+?)\s*=\s*([^|]+)$/i', explode('|', $entry)[0], $nameAndUrl, PREG_UNMATCHED_AS_NULL)) {
                $this->warn('INVALID_SERVER', "Invalid environment entry \"{$entry}\"; expected Name=https://host", $blockLines);
                continue;
            }
            $name = trim($nameAndUrl[1]);
            $canonical = $this->normalizeServerUrl($nameAndUrl[2]);
            if ($canonical === null) {
                $this->warn('INVALID_SERVER', "Invalid server URL \"{$nameAndUrl[2]}\" in environment entry \"{$entry}\".", $blockLines);
                continue;
            }
            $server = ['url' => $canonical, 'description' => $name];
            foreach (array_slice(explode('|', $entry), 1) as $setting) {
                $pieces = explode('=', $setting);
                $key = array_shift($pieces);
                $normalizedKey = strtolower(trim((string) $key));
                $value = trim(implode('=', $pieces));
                if ($value === '' || !in_array($normalizedKey, ['login', 'token', 'method'], true)) {
                    $this->warn('INVALID_SERVER', "Invalid environment entry \"{$entry}\"; unknown or empty setting \"{$setting}\".", $blockLines);
                    $settingErrors = true;
                    break;
                }
                if ($normalizedKey === 'login') {
                    if (str_starts_with($value, '/') && !preg_match('/[\s?#]/', $value)) {
                        $server['x-login-url'] = rtrim($value, '/');
                    } else {
                        $loginUrl = $this->normalizeServerUrl($value);
                        if ($loginUrl === null) {
                            $this->warn('INVALID_SERVER', "Invalid environment entry \"{$entry}\"; login must be an absolute HTTP(S) URL or a path beginning with /.", $blockLines);
                            $settingErrors = true;
                            break;
                        }
                        $server['x-login-url'] = $loginUrl;
                    }
                } elseif ($normalizedKey === 'token') {
                    $server['x-token-path'] = $value;
                } else {
                    $method = strtoupper($value);
                    if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                        $this->warn('INVALID_SERVER', "Invalid environment entry \"{$entry}\"; invalid login method \"{$value}\".", $blockLines);
                        $settingErrors = true;
                        break;
                    }
                    $server['x-login-method'] = $method;
                }
            }
            if (!$settingErrors) {
                $this->servers[] = $server;
            }
        }
    }

    /** A server URL must be absolute HTTP(S) with no credentials, query or fragment; trailing slashes are dropped. */
    private function normalizeServerUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\s,\'"\\#<>(){}]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        if (!isset($parts['host']) || $parts['host'] === '') {
            return null;
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $authority = isset($parts['port']) ? $parts['host'] . ':' . $parts['port'] : $parts['host'];
        $url = $scheme . '://' . $authority . ($parts['path'] ?? '');
        return rtrim($url, '/');
    }

    private function applyPublicTargets(): void
    {
        foreach ($this->publicTargets as $target) {
            $suffix = '/' . ltrim($target, '/');
            $matched = false;
            foreach ($this->endpoints as &$endpoint) {
                if (str_ends_with($endpoint['path'], $suffix)) {
                    $endpoint['public'] = true;
                    $matched = true;
                }
            }
            unset($endpoint);
            if (!$matched) {
                $this->warn('UNRESOLVED_PUBLIC_ENDPOINT', "Public endpoint \"{$target}\" matches no documented endpoint.", [1, 1]);
            }
        }
    }

    private function readTables(array $sections): void
    {
        foreach ($sections as $section) {
            foreach ($section['blocks'] as $block) {
                if ($block['kind'] !== 'table') {
                    continue;
                }
                $headers = array_map(fn(string $header): string => $this->normalizeHeader($header), $block['header']);
                if (in_array('method', $headers, true) && in_array('endpoint', $headers, true)) {
                    $this->readEndpointTable($block, $headers, $section, $sections);
                } elseif (in_array('entity', $headers, true) && $this->referenceMethods($headers)) {
                    $this->readReferenceTable($block, $headers, $section, $sections);
                }
            }
        }
    }

    private function targetsNamedInLabel(string $label, array $targets): array
    {
        preg_match_all('/`([^`]+\.php)`/i', $label, $matches);
        if (!$matches[1]) {
            return [];
        }
        $files = array_map('strtolower', $matches[1]);
        return array_values(array_filter($targets, function (int $index) use ($files): bool {
            $file = strtolower(basename(parse_url($this->endpoints[$index]['path'], PHP_URL_PATH) ?: ''));
            return in_array($file, $files, true);
        }));
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim(str_replace(['`', '*', '_', '-'], ' ', $header)));
        $header = preg_replace('/\s+/', ' ', $header);
        if (in_array($header, ['http method', 'verb', 'http verb'], true)) {
            return 'method';
        }
        if (in_array($header, ['path', 'route', 'url', 'resource', 'endpoint path', 'api path', 'api route'], true)) {
            return 'endpoint';
        }
        if ($header === 'permissions') {
            return 'permission';
        }
        if (in_array($header, ['authentication', 'auth column'], true)) {
            return 'auth';
        }
        return $header;
    }

    private function readEndpointTable(array $table, array $headers, array $section, array $sections): void
    {
        $columns = array_flip($headers);
        // Cells in unrecognized columns would otherwise be silently dropped while the user
        // believes their data is in the spec. Say which columns are being set aside.
        $unknownColumns = array_values(array_unique(array_diff(
            array_filter($headers, fn(string $header): bool => $header !== ''),
            ['method', 'endpoint', 'description', 'permission', 'auth'],
        )));
        if ($unknownColumns) {
            $this->warn(
                'UNRECOGNIZED_COLUMN',
                'Column(s) ' . implode(', ', $unknownColumns) . ' are not recognized; their cells are ignored.',
                $table['lines'],
            );
        }
        foreach ($table['rows'] as $row) {
            $cells = $row['cells'];
            $methods = $this->parseMethods($cells[$columns['method']] ?? '');
            $targetCell = $cells[$columns['endpoint']] ?? '';
            $target = $this->codeSpans($targetCell)[0] ?? $this->stripInline($targetCell);
            if (!$methods || $target === '') {
                $this->warn('INVALID_TABLE_ROW', 'Row is not an endpoint: ' . $this->stripInline(implode(' | ', $cells)), [$row['line'], $row['line']]);
                continue;
            }
            [$path, $query, $unresolved] = $this->resolvePath($target, $section, $sections);
            $description = $cells[$columns['description'] ?? '\x00'] ?? '';
            $auth = isset($columns['auth']) ? $this->authValue($cells[$columns['auth']] ?? '') : null;
            $permissionText = $cells[$columns['permission'] ?? '\x00'] ?? '';
            if (!isset($columns['permission']) && isset($columns['auth']) && $auth === null) {
                $permissionText = $cells[$columns['auth']];
            }                foreach ($methods as $method) {
                    $endpoint = $this->newEndpoint($method, $path, $section, $row['line'], $description);
                    $endpoint['tableId'] = $table['lines'][0];
                    $endpoint['detailSectionIds'][] = $section['id'];
                    $endpoint['query'] = $query;
                    $endpoint['permission'] = $this->parsePermissions($permissionText);
                    // "`customers:view` or `customers:view_all`": either permission is enough. Kept as
                    // written in x-permissions-any-of instead of an (all-of) x-permissions list.
                    $anyOf = $this->parseAnyOfPermissions($permissionText);
                    if ($anyOf) {
                        $endpoint['permission'] = [];
                        $endpoint['permissionAnyOf'] = $anyOf;
                    }
                    if ($auth !== null) {
                        $endpoint['public'] = !$auth;
                    }
                    $endpoint['public'] = $endpoint['public'] ?? $this->authValue($description) === false;
                    $endpoint['description'] = $this->cleanDescription($description);
                    // Every parameter source funnels through addParameter(), which dedupes by
                    // name+in — otherwise `?search=` in the path template and a `?search=` mention
                    // in the description each end up as their own parameter.
                    foreach ($this->queryTemplateParameters($query, $row['line']) as $parameter) {
                        $this->addParameter($endpoint, $parameter);
                    }
                    foreach ($this->parseMentionedQueryParams($description, [$row['line'], $row['line']]) as $parameter) {
                        $this->addParameter($endpoint, $parameter);
                    }
                    if (preg_match('/^Paginated\b/i', $this->stripInline($description))) {
                        foreach ($this->paginationParameters as $parameter) {
                            $this->addParameter($endpoint, $parameter);
                        }
                    }
                    foreach ($this->parseHeaderParameters($description, [$row['line'], $row['line']]) as $parameter) {
                        $this->addParameter($endpoint, $parameter);
                    }
                $this->applyShorthand($endpoint, $targetCell);
                // A `{name, email, phone?}` span in the description is the JSON body of a write
                // endpoint whose Endpoint cell has none.
                if (!isset($endpoint['request']['body']) && in_array($method, self::WRITE_METHODS, true)) {
                    $this->applyShorthand($endpoint, '`-` ' . $description);
                }
                $this->applyDescriptionFacts($endpoint, $description);
                if ($unresolved) {
                    $this->warn('UNRESOLVED_PREFIX', "No path prefix found for \"{$target}\"", [$row['line'], $row['line']], $endpoint);
                }
                $this->endpoints[] = $endpoint;
                if (preg_match('/Also reachable at\s+`([^`]+)`\s*\(alias\)/i', $description, $aliasMatch)) {
                    [$aliasPath, $aliasQuery, $aliasUnresolved] = $this->resolvePath($aliasMatch[1], $section, $sections);
                    $alias = $endpoint;
                    $alias['path'] = $aliasPath;
                    $alias['query'] = $aliasQuery;
                    $alias['aliasOf'] = $method . ' ' . $path;
                    foreach ($this->queryTemplateParameters($aliasQuery, $row['line']) as $parameter) {
                        $this->addParameter($alias, $parameter);
                    }
                    if ($aliasUnresolved) {
                        $this->warn('UNRESOLVED_PREFIX', "No path prefix found for alias \"{$aliasMatch[1]}\"", [$row['line'], $row['line']], $alias);
                    }
                    $this->endpoints[] = $alias;
                }
            }
        }
    }

    private function referenceMethods(array $headers): bool
    {
        foreach ($headers as $header) {
            if (preg_match('/\((?:get|post|put|patch|delete|head|options)/i', $header)) {
                return true;
            }
        }
        return false;
    }

    private function readReferenceTable(array $table, array $headers, array $section, array $sections): void
    {
        $entityColumn = array_search('entity', $headers, true);
        $permissionsColumn = array_search('permission', $headers, true);
        $authColumn = array_search('auth', $headers, true);
        $this->warn('INFERRED_PERMISSION', 'Reference-table permissions are inferred from their List/Single/Create/Update/Remove columns.', $table['lines']);
        foreach ($table['rows'] as $row) {
            $entity = $this->stripInline($row['cells'][$entityColumn] ?? '');
            $permissions = $this->parsePermissions($row['cells'][$permissionsColumn] ?? '');
            $auth = $authColumn === false ? null : $this->authValue($row['cells'][$authColumn] ?? '');
            foreach ($headers as $index => $header) {
                if (!preg_match('#\(([a-z/ ]+)\)\s*$#i', $header, $match)) {
                    continue;
                }
                $methods = $this->parseMethods($match[1]);
                $target = $this->codeSpans($row['cells'][$index] ?? '')[0] ?? '';
                if (!$methods || $target === '') {
                    continue;
                }
                [$path, $query, $unresolved] = $this->resolvePath($target, $section, $sections);
                foreach ($methods as $method) {
                    $endpoint = $this->newEndpoint($method, $path, $section, $row['line'], $entity . ': ' . preg_replace('/\s*\([^)]*\)/', '', $table['header'][$index]));
                    $endpoint['query'] = $query;
                    $endpoint['request']['parameters'] = $this->queryTemplateParameters($query, $row['line']);
                    $actions = [];
                    if (preg_match('/\b(list|single)\b/i', $header)) $actions[] = 'view';
                    if (preg_match('/\bcreate\b/i', $header)) $actions[] = 'add';
                    if (preg_match('/\bupdate\b/i', $header)) $actions[] = 'edit';
                    if (preg_match('/\b(remove|delete)\b/i', $header)) $actions[] = 'delete';
                    $endpoint['permission'] = array_values(array_filter($permissions, static function (string $permission) use ($actions): bool {
                        $parts = explode(':', $permission);
                        return count($parts) === 2 && in_array(strtolower($parts[1]), $actions, true);
                    }));
                    if ($auth !== null) {
                        $endpoint['public'] = !$auth;
                    }
                    $this->applyShorthand($endpoint, $row['cells'][$index] ?? '');
                    if ($unresolved) {
                        $this->warn('UNRESOLVED_PREFIX', "No path prefix found for \"{$target}\"", [$row['line'], $row['line']], $endpoint);
                    }
                    $this->endpoints[] = $endpoint;
                }
            }
        }
    }

    private function readEndpointHeadings(array $sections): void
    {
        foreach ($sections as $section) {
            if (!$section['heading'] || !preg_match('/^\s*((?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)(?:\s*(?:\/|\bor\b)\s*(?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS))*)\s+`?([^` —]+)`?(?:\s*—\s*`(\{[^`]*\})`)?/i', $section['heading']['text'], $match)) {
                continue;
            }
            $methods = $this->parseMethods($match[1]);
            if (!$methods) {
                continue;
            }
            [$path, $query, $unresolved] = $this->resolvePath(trim($match[2]), $section, $sections);
            foreach ($methods as $method) {
                $existing = $this->findHeadingTarget($method, $path, $section, $sections);
                if ($existing !== null) {
                    $this->endpoints[$existing]['detailSectionIds'][] = $section['id'];
                    continue;
                }
                $endpoint = $this->newEndpoint($method, $path, $section, $section['heading']['lines'][0], '');
                $endpoint['query'] = $query;
                $endpoint['request']['parameters'] = $this->queryTemplateParameters($query, $section['heading']['lines'][0]);
                $endpoint['detailSectionIds'][] = $section['id'];
                if (!empty($match[3])) {
                    $endpoint['request']['body'] = [
                        'contentType' => 'application/json',
                        'schema' => $this->shorthandSchema($match[3]),
                    ];
                }
                if ($unresolved) {
                    $this->warn('UNRESOLVED_PREFIX', "No path prefix found for \"{$match[2]}\"", $section['heading']['lines'], $endpoint);
                }
                $this->endpoints[] = $endpoint;
            }
        }
    }

    private function findHeadingTarget(string $method, string $path, array $section, array $sections): ?int
    {
        $matches = [];
        foreach ($this->endpoints as $index => $endpoint) {
            if ($endpoint['method'] !== $method || basename(parse_url($endpoint['path'], PHP_URL_PATH) ?: '') !== basename(parse_url($path, PHP_URL_PATH) ?: '')) {
                continue;
            }
            if ($this->isWithin($endpoint['sectionId'], $section['parent'], $sections)) {
                $matches[] = $index;
            }
        }
        foreach ($matches as $index) {
            if ($this->endpoints[$index]['path'] === $path) {
                return $index;
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function bindEndpointDetails(array $sections): void
    {
        foreach ($sections as $section) {
            $targets = [];
            foreach ($this->endpoints as $index => $endpoint) {
                if (in_array($section['id'], $endpoint['detailSectionIds'], true) ||
                    $this->isWithin($section['id'], $endpoint['sectionId'], $sections)) {
                    $targets[] = $index;
                }
            }
            $sectionTargets = array_values(array_filter($targets, fn(int $index): bool =>
                in_array($section['id'], $this->endpoints[$index]['detailSectionIds'], true)
            ));
            if ($sectionTargets) {
                $targets = $sectionTargets;
            }
            if (!$targets) {
                continue;
            }
            for ($i = 0; $i < count($section['blocks']); $i++) {
                $block = $section['blocks'][$i];
                if ($block['kind'] !== 'paragraph') {
                    continue;
                }
                $labelledTargets = $this->targetsNamedInLabel($block['text'], $targets);
                $paragraphTargets = $labelledTargets ?: $targets;
                if (preg_match('/\bAuth\s*:\s*(true|false)\b/i', $block['text'], $auth)) {
                    foreach ($paragraphTargets as $target) {
                        $this->endpoints[$target]['public'] = strtolower($auth[1]) === 'false';
                    }
                }
                if (preg_match('/^No token (?:is )?required\b/i', trim($block['text']))) {
                    foreach ($paragraphTargets as $target) {
                        $this->endpoints[$target]['public'] = true;
                    }
                }
                foreach ($paragraphTargets as $target) {
                    $text = trim($block['text']);
                    if (!preg_match('/^(?:`[^`]+\.php`\s+)?(?:Request|Response|Success)(?:\s+`[^`]+`)?\s*:\s*$/i', $text) &&
                        !preg_match('/^(?:Query params?|(?:Remove )?Errors?)\s*:/i', $text) &&
                        !preg_match('/^Auth\s*:/i', $text) &&
                        !preg_match('/^No token (?:is )?required\b/i', $text)) {
                        $note = $this->cleanDescription($text);
                        if ($note !== '' && !str_contains($this->endpoints[$target]['description'], $note)) {
                            $this->endpoints[$target]['description'] = trim($this->endpoints[$target]['description'] . "\n\n" . $note);
                            if ($this->endpoints[$target]['summary'] === '') {
                                $this->endpoints[$target]['summary'] = $this->firstSentence($note);
                            }
                        }
                    }
                    $this->applyDescriptionFacts($this->endpoints[$target], $block['text']);
                }
                if (($section['blocks'][$i + 1]['kind'] ?? null) !== 'fence' ||
                    !in_array($section['blocks'][$i + 1]['lang'], ['', 'json'], true)) {
                    continue;
                }
                $jsonTargets = $labelledTargets ?: $targets;
                $label = trim($block['text']);
                if (!preg_match('/(?:request|response|success)\b/i', $label)) {
                    $this->warn('UNBOUND_JSON_BLOCK', 'JSON example has no Request, Response, or Success label; not bound.', $section['blocks'][$i + 1]['lines']);
                    continue;
                }
                $value = $this->decodeJsonExample($section['blocks'][$i + 1]['text']);
                if ($value === null) {
                    $this->warn('INVALID_JSON_BLOCK', 'JSON example could not be read.', $section['blocks'][$i + 1]['lines']);
                    continue;
                }
                $isRequest = preg_match('/\brequest\b/i', $label);
                $status = 200;
                if (preg_match('/(?:success|response)\s+`?(\d{3})/i', $label, $statusMatch)) {
                    $status = (int)$statusMatch[1];
                }
                foreach ($jsonTargets as $target) {
                    if ($isRequest) {
                        if (in_array($this->endpoints[$target]['method'], ['GET', 'HEAD'], true)) {
                            $this->warn('UNBOUND_JSON_BLOCK', 'Request body documented for a bodyless method; ignored.', $section['blocks'][$i + 1]['lines'], $this->endpoints[$target]);
                            continue;
                        }
                        $this->endpoints[$target]['request']['body'] = [
                            'contentType' => 'application/json',
                            'schema' => $this->inferSchema($value, $this->jsonComments($section['blocks'][$i + 1]['text'])),
                            'example' => $value,
                        ];
                    } else {
                        $wrapped = stripos($label, '`data`') !== false;
                        $responseValue = $wrapped && $this->responseEnvelope !== null
                            ? array_merge($this->responseEnvelope, ['data' => $value])
                            : $value;
                        $this->endpoints[$target]['responses'][$status] = [
                            'description' => $status >= 400 ? $this->reasonPhrase($status) : $this->reasonPhrase($status),
                            'content' => [
                                'application/json' => [
                                'schema' => $this->inferSchema($responseValue, $this->jsonComments($section['blocks'][$i + 1]['text'])),
                                    'example' => $responseValue,
                                ],
                            ],
                        ];
                    }
                }
            }
        }
    }

    private function attachTableNotes(array $sections): void
    {
        foreach ($sections as $section) {
            $blockCount = count($section['blocks']);
            for ($i = 0; $i < $blockCount; $i++) {
                $block = $section['blocks'][$i];
                if ($block['kind'] !== 'table') {
                    continue;
                }
                $endpointIndexes = [];
                foreach ($this->endpoints as $index => $endpoint) {
                    if (($endpoint['tableId'] ?? null) === $block['lines'][0]) {
                        $endpointIndexes[] = $index;
                    }
                }
                if (!$endpointIndexes) {
                    continue;
                }
                for ($next = $i + 1; $next < $blockCount && $section['blocks'][$next]['kind'] === 'paragraph'; $next++) {
                    $text = $this->stripInline($section['blocks'][$next]['text']);
                    if (preg_match('/^(?:Query params?|(?:Remove )?Errors?|Base path|Auth)\s*:/i', trim($text)) ||
                        preg_match('/^Accepts\s+`?\?/i', trim($text)) ||
                        preg_match('#^(?:[A-Za-z0-9_./-]+\.php\s+)?(?:Request|Response|Success)\b#i', trim($text)) ||
                        preg_match('/^No token (?:is )?required\.?$/i', trim($text))) {
                        continue;
                    }
                    // A note that names an endpoint file in backticks goes to that endpoint only.
                    $named = $this->targetsNamedInLabel($section['blocks'][$next]['text'], $endpointIndexes);
                    foreach ($named ?: $endpointIndexes as $index) {
                        $description = trim($this->endpoints[$index]['description']);
                        if (!str_contains($description, $text)) {
                            $this->endpoints[$index]['description'] = trim($description . "\n\n" . $text);
                        }
                    }
                }
            }
        }
    }

    private function newEndpoint(string $method, string $path, array $section, int $line, string $description): array
    {
        $tag = '';
        $sectionId = $section['id'];
        while ($sectionId !== null) {
            $ancestor = null;
            foreach ($this->sectionsForTag as $candidate) {
                if ($candidate['id'] === $sectionId) {
                    $ancestor = $candidate;
                    break;
                }
            }
            if ($ancestor === null) {
                break;
            }
            if ($ancestor['level'] === 2) {
                $tag = $ancestor['title'];
                break;
            }
            $sectionId = $ancestor['parent'];
        }
        return [
            'method' => $method,
            'path' => $path,
            'query' => '',
            'sectionId' => $section['id'],
            'detailSectionIds' => [],
            'tag' => $tag,
            'summary' => $this->firstSentence($this->cleanDescription($description)),
            'description' => $this->cleanDescription($description),
            'public' => false,
            'permission' => [],
            'request' => ['parameters' => []],
            'responses' => [],
            'line' => $line,
        ];
    }

    private function applyDescriptionFacts(array &$endpoint, string $text): void
    {
        foreach ($this->parseMentionedQueryParams($text, [$endpoint['line'], $endpoint['line']]) as $parameter) {
            $this->addParameter($endpoint, $parameter);
        }
        foreach ($this->parseHeaderParameters($text, [$endpoint['line'], $endpoint['line']]) as $parameter) {
            $this->addParameter($endpoint, $parameter);
        }
        if (preg_match_all('/(?:\*\*)?requires(?:\*\*)?\s+`([A-Za-z_][A-Za-z0-9_]*)`/i', $text, $required)) {
            foreach ($required[1] as $name) {
                $this->addParameter($endpoint, [
                    'name' => $name,
                    'in' => 'query',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ]);
            }
        }
        if (preg_match('/\bAuth\s*:\s*false\b/i', $text)) {
            $endpoint['public'] = true;
        } elseif (preg_match('/\bAuth\s*:\s*true\b/i', $text)) {
            $endpoint['public'] = false;
        }
        $endpointIsRemove = preg_match('/remove/i', basename(parse_url($endpoint['path'], PHP_URL_PATH) ?: '')) === 1;
        if (preg_match('/^(Remove )?Errors?\s*:\s*(.+)$/im', $text, $errors)) {
            $removeOnly = !empty($errors[1]);
            if (!$removeOnly || $endpointIsRemove) {
                preg_match_all('/`(\d{3})`\s*([^,;\n.]*)/u', $errors[2], $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $status = (int)$match[1];
                    if ($status < 400) {
                        continue;
                    }
                    $endpoint['responses'][$status] = ['description' => trim($match[2]) ?: $this->reasonPhrase($status)];
                }
            }
        }
        // A "Remove errors:" line only documents remove endpoints, so its codes are not mentions for the others.
        $mentionText = $endpointIsRemove ? $text : (string) preg_replace('/^Remove Errors?\s*:.*$/im', '', $text);
        if (preg_match_all('/`(\d{3})`\s*([^,;\n.]*)/u', $mentionText, $mentions, PREG_SET_ORDER)) {
            foreach ($mentions as $mention) {
                $status = (int)$mention[1];
                if ($status < 400) {
                    continue;
                }
                $endpoint['responses'][$status] ??= [
                    'description' => trim($mention[2]) ?: $this->reasonPhrase($status),
                ];
            }
        }
        if (preg_match('/\bResponse(?:\s+(\d{3}))?\s*:\s*([a-z0-9.+-]+\/[a-z0-9.+-]+)/i', $text, $response)) {
            $status = isset($response[1]) && $response[1] !== '' ? (int)$response[1] : 200;
            $contentType = strtolower($response[2]);
            $binary = preg_match('/^(?:application\/(?:octet-stream|pdf|zip)|image\/|audio\/|video\/)/', $contentType);
            $endpoint['responses'][$status] ??= [
                'description' => 'Response content type ' . $contentType,
                'content' => [
                    $contentType => [
                        'schema' => $binary ? ['type' => 'string', 'format' => 'binary'] : ['type' => 'string'],
                    ],
                ],
            ];
        }
        if (preg_match('/multipart\/form-data/i', $text)) {
            preg_match_all('/(?:file\s+field\s+)?`([A-Za-z_][A-Za-z0-9_]*)(\?)?`/i', $text, $fields, PREG_SET_ORDER);
            $properties = [];
            $required = [];
            foreach ($fields as $field) {
                $name = $field[1];
                $isFile = preg_match('/file\s+field\s*`' . preg_quote($name, '/') . '/i', $text) === 1;
                $properties[$name] = $isFile
                    ? ['type' => 'string', 'format' => 'binary']
                    : ['type' => 'string'];
                if (($field[2] ?? '') !== '?') {
                    if (!in_array($name, $required, true)) $required[] = $name; // listed once even if named twice
                }
            }
            if ($properties) {
                $endpoint['request']['body'] = [
                    'contentType' => 'multipart/form-data',
                    'schema' => [
                        'type' => 'object',
                        'properties' => (object)$properties,
                        ...($required ? ['required' => $required] : []),
                    ],
                ];
            }
        }
        foreach ($this->parseProseHeaderParameters($text) as $parameter) {
            $documented = false;
            foreach ($endpoint['request']['parameters'] as $existing) {
                $documented = $documented || ($existing['in'] === 'header' && strtolower($existing['name']) === strtolower($parameter['name']));
            }
            if (!$documented) {
                $this->addParameter($endpoint, $parameter);
            }
        }
    }

    /** "Requests must include the `Idempotency-Key` header" -> a header parameter (required when the sentence says so). */
    private function parseProseHeaderParameters(string $text): array
    {
        $parameters = [];
        preg_match_all('/`([A-Za-z0-9-]+)`\s+header\b/i', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            [$whole, $offset] = $match[0];
            $before = substr($text, 0, $offset);
            $lineStart = ($newline = strrpos($before, "\n")) === false ? 0 : $newline + 1;
            // "Headers: `X-Trace` header ..." lines are declarations, read by parseHeaderParameters().
            if (preg_match('/^\s*(?:header(?:\s+params?)?|headers)\s*:/im', substr($text, $lineStart, $offset + 1 - $lineStart))) {
                continue;
            }
            $start = max(
                ($dot = strrpos($before, '.')) === false ? -1 : $dot,
                ($newline = strrpos($before, "\n")) === false ? -1 : $newline,
                ($semicolon = strrpos($before, ';')) === false ? -1 : $semicolon,
            ) + 1;
            $sentence = substr($text, $start, $offset + strlen($whole) - $start);
            $parameters[] = [
                'name' => $match[1][0],
                'in' => 'header',
                'required' => !preg_match('/\b(?:optional|not required)\b/i', $sentence) && preg_match('/\b(?:must|required|requires?|include)\b/i', $sentence) === 1,
                'schema' => ['type' => 'string'],
            ];
        }
        return $parameters;
    }

    /** Every operation gets a unique operationId derived from its method and path (`get_widgets_list_php`, `_2` on collisions). */
    private function operationId(string $method, string $path, array &$used): string
    {
        $base = strtolower($method) . '_' . trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', ltrim($path, '/')), '_');
        if ($base === '' || $base === strtolower($method) . '_') {
            $base = strtolower($method) . '_root';
        }
        $id = $base;
        for ($n = 2; isset($used[$id]); $n++) {
            $id = $base . '_' . $n;
        }
        $used[$id] = true;
        return $id;
    }

    private function parseMethods(string $text): array
    {
        preg_match_all('/GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS/i', $this->stripInline($text), $matches);
        $methods = array_map('strtoupper', $matches[0]);
        return array_values(array_unique(array_filter($methods, static fn(string $method): bool => in_array($method, self::METHODS, true))));
    }

    private function resolvePath(string $target, array $section, array $sections): array
    {
        $target = trim($target);
        $bodyShorthand = strpos($target, ' — ');
        if ($bodyShorthand !== false) {
            $target = substr($target, 0, $bodyShorthand);
        }
        $query = '';
        $question = strpos($target, '?');
        if ($question !== false) {
            $query = substr($target, $question + 1);
            $target = substr($target, 0, $question);
        }
        $target = trim($target, '` ');
        $prefix = $section['prefix'];
        $path = str_starts_with($target, '/') || ($prefix !== null && ($target === $prefix || str_starts_with($target, rtrim($prefix, '/') . '/')))
            ? $target
            : ($prefix ? $prefix . '/' . $target : $target);
        $unresolved = !str_starts_with($target, '/') && !$prefix;
        $path = '/' . trim(preg_replace('#/{2,}#', '/', $path), '/');
        return [$path === '/' ? '/' : rtrim($path, '/'), $query, $unresolved];
    }

    private function queryTemplateParameters(string $query, int $line): array
    {
        if ($query === '') {
            return [];
        }
        $parameters = [];
        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                continue;
            }
            $parameters[] = [
                'name' => $name,
                'in' => 'query',
                'required' => true,
                'schema' => $this->querySchema($value),
                'example' => $this->queryExample($value),
            ];
        }
        return $parameters;
    }

    private function querySchema(string $value): array
    {
        if (strcasecmp($value, 'YYYY-MM-DD') === 0) return ['type' => 'string', 'format' => 'date'];
        if (preg_match('/^-?\d+$/', $value)) return ['type' => 'integer'];
        if (preg_match('/^-?\d+\.\d+$/', $value)) return ['type' => 'number'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return ['type' => 'string', 'format' => 'date'];
        if (str_contains($value, '|')) return ['type' => 'string', 'enum' => explode('|', $value)];
        return ['type' => 'string'];
    }

    private function queryExample(string $value): mixed
    {
        if (preg_match('/^-?\d+$/', $value)) return (int)$value;
        if (preg_match('/^-?\d+\.\d+$/', $value)) return (float)$value;
        return $value === '' ? null : $value;
    }

    private function parseMentionedQueryParams(string $text, array $lines): array
    {
        $parameters = [];
        if (preg_match_all('/`(\??[A-Za-z_][A-Za-z0-9_]*=([^`]*)?)`/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (!str_contains($match[1], '?') && !preg_match('/^Accepts\s+/i', $text)) {
                    continue;
                }
                $piece = ltrim($match[1], '?');
                [$name, $value] = array_pad(explode('=', $piece, 2), 2, '');
                $schema = $this->querySchema($value);
                if (preg_match('/\(([^)]*)\)/', substr($text, strpos($text, $match[0]) + strlen($match[0])), $details)) {
                    if (preg_match('/default\s+`?([\w.-]+)`?/i', $details[1], $default)) {
                        $schema['default'] = $this->queryExample($default[1]);
                        if (is_int($schema['default'])) $schema['type'] = 'integer';
                    }
                    if (preg_match('/max\s+(\d+)/i', $details[1], $max) && $schema['type'] === 'integer') {
                        $schema['maximum'] = (int)$max[1];
                    }
                }
                if (str_contains($value, '|')) {
                    $schema['enum'] = array_values(array_unique(explode('|', $value)));
                }
                $parameters[] = [
                    'name' => $name,
                    'in' => 'query',
                    'required' => preg_match('/\brequired\b/i', $text) === 1,
                    'schema' => $schema,
                ];
            }
        }
        if (preg_match('/Query params:\s*(.+)/is', $text, $list)) {
            preg_match_all('/`([A-Za-z_][A-Za-z0-9_]*)`(?:\s*\(([^)]*)\))?/', $list[1], $items, PREG_SET_ORDER);
            foreach ($items as $item) {
                $details = $item[2] ?? '';
                $schema = ['type' => 'string'];
                if (preg_match_all('/`([^`]+)`/', $details, $enums) && count($enums[1]) > 1) {
                    $schema['enum'] = $enums[1];
                }
                if (preg_match('/default\s+`?([\w.-]+)`?/i', $details, $default)) {
                    $schema['default'] = $this->queryExample($default[1]);
                    if (is_int($schema['default'])) $schema['type'] = 'integer';
                }
                if (isset($schema['enum'])) {
                    $schema['enum'] = array_values(array_unique($schema['enum']));
                }
                $parameters[] = [
                    'name' => $item[1],
                    'in' => 'query',
                    'required' => preg_match('/\brequired\b/i', $details) === 1,
                    'schema' => $schema,
                ];
            }
        }
        $unique = [];
        foreach ($parameters as $parameter) {
            $unique[$parameter['in'] . ':' . $parameter['name']] = $parameter;
        }
        return array_values($unique);
    }

    private function parseHeaderParameters(string $text, array $lines): array
    {
        $parameters = [];
        if (preg_match_all('/^\s*(?:header(?:\s+params?)?|headers)\s*:\s*(.+)$/im', $text, $declarations)) {
            foreach ($declarations[1] as $declaration) {
                preg_match_all('/`([A-Za-z0-9-]+)`(?:\s*\(([^)]*)\))?/', $declaration, $headers, PREG_SET_ORDER);
                foreach ($headers as $header) {
                    $parameters[] = [
                        'name' => $header[1],
                        'in' => 'header',
                        'required' => !preg_match('/\boptional\b/i', $header[2] ?? ''),
                        'schema' => ['type' => 'string'],
                    ];
                }
            }
        }
        return $parameters;
    }

    private function addParameter(array &$endpoint, array $parameter): void
    {
        foreach ($endpoint['request']['parameters'] as &$existing) {
            if ($existing['in'] === $parameter['in'] && $existing['name'] === $parameter['name']) {
                $existing = array_merge($existing, $parameter);
                unset($existing);
                return;
            }
        }
        unset($existing);
        $endpoint['request']['parameters'][] = $parameter;
    }

    private function authValue(string $text): ?bool
    {
        $text = trim($this->stripInline($text));
        if (preg_match('/^(?:Auth\s*:\s*)?(true|false)$/i', $text, $match)) {
            return strtolower($match[1]) === 'true';
        }
        return null;
    }

    private function parsePermissions(string $text): array
    {
        preg_match_all('/`([^`]+)`(?:\/([a-z]+))?/i', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $permissions = [];
        $resource = null;
        $previousEnd = -1;
        foreach ($matches as $match) {
            $code = trim($match[1][0]);
            $gap = $previousEnd === -1 ? '' : substr($text, $previousEnd, $match[0][1] - $previousEnd);
            $previousEnd = $match[0][1] + strlen($match[0][0]);
            $extra = isset($match[2]) && $match[2][1] !== -1 ? $match[2][0] : '';
            if (!preg_match('/^([a-z0-9_*.-]+):([a-z0-9_*.-]+(?:\/[a-z0-9_*.-]+)*)$/i', $code, $parts)) {
                // "`customers:add`/`edit`": a bare action after "/" continues the previous resource.
                if ($resource !== null && preg_match('/^\s*\/\s*$/', $gap) && preg_match('/^[a-z0-9_*.-]+$/i', $code)) {
                    foreach (array_filter([strtolower($code), strtolower($extra)]) as $action) {
                        if (in_array($action, ['view', 'add', 'edit', 'delete'], true)) {
                            $permissions[] = strtolower($resource . ':' . $action);
                        }
                    }
                } else {
                    $resource = null;
                }
                continue;
            }
            $resource = $parts[1];
            $match = [$match[0][0], $match[1][0], $extra];
            $actions = explode('/', strtolower($parts[2]));
            if (!empty($match[2])) {
                $actions[] = strtolower($match[2]);
            }
            foreach ($actions as $action) {
                if (in_array($action, ['view', 'add', 'edit', 'delete'], true)) {
                    $permissions[] = strtolower($parts[1] . ':' . $action);
                }
            }
        }
        return array_values(array_unique($permissions));
    }

    /** "`tickets:view` or `tickets:view_all`" -> both codes (empty unless the cell says "or" between two or more codes). */
    private function parseAnyOfPermissions(string $text): array
    {
        if (!preg_match('/\bor\b/i', (string) preg_replace('/`[^`]*`/', ' ', $text))) {
            return [];
        }
        $codes = array_values(array_filter(
            array_map('trim', $this->codeSpans($text)),
            static fn(string $code): bool => preg_match('/^[A-Za-z0-9_*.-]+:[A-Za-z0-9_*.-]+$/', $code) === 1,
        ));
        $codes = array_values(array_unique($codes));
        return count($codes) > 1 ? $codes : [];
    }

    private function applyShorthand(array &$endpoint, string $cell): void
    {
        $spans = $this->codeSpans($cell);
        foreach (array_slice($spans, 1) as $span) {
            if (str_starts_with($span, '{') && str_ends_with($span, '}')) {
                $endpoint['request']['body'] = [
                    'contentType' => 'application/json',
                    'schema' => $this->shorthandSchema($span),
                ];
                return;
            }
        }
    }

    private function shorthandSchema(string $text): array
    {
        $properties = [];
        $required = [];
        $inner = trim($text, '{} ');
        if (str_contains($inner, ':')) {
            // Typed shorthand: `{id: integer, tags: string[], note?}`.
            foreach (explode(',', $inner) as $entry) {
                if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(\?)?\s*(?::\s*(.*))?$/s', trim($entry), $field)) {
                    continue;
                }
                $properties[$field[1]] = $this->shorthandValueSchema(trim($field[3] ?? ''));
                if (($field[2] ?? '') !== '?') {
                    if (!in_array($field[1], $required, true)) $required[] = $field[1];
                }
            }
        } else {
            preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)(\?)?/', $inner, $fields, PREG_SET_ORDER);
            foreach ($fields as $field) {
                $properties[$field[1]] = ['type' => 'string'];
                if (($field[2] ?? '') !== '?') {
                    if (!in_array($field[1], $required, true)) $required[] = $field[1];
                }
            }
        }
        $schema = ['type' => 'object', 'properties' => (object)$properties];
        if ($required) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /** The type in typed shorthand: string, integer, number, boolean, object, `T[]` or `array<T>`; anything else is a string. */
    private function shorthandValueSchema(string $value): array
    {
        $types = ['string', 'integer', 'number', 'boolean', 'object'];
        $primitive = strtolower($value);
        if (in_array($primitive, $types, true)) {
            return ['type' => $primitive];
        }
        if (preg_match('/^(?:array\s*<\s*([A-Za-z]+)\s*>|([A-Za-z]+)\[\])$/i', $value, $array)) {
            $itemType = strtolower(($array[1] ?? '') !== '' ? $array[1] : ($array[2] ?? ''));
            if (in_array($itemType, $types, true)) {
                return ['type' => 'array', 'items' => ['type' => $itemType]];
            }
        }
        return ['type' => 'string'];
    }

    private function decodeJsonExample(string $text): mixed
    {
        $clean = '';
        foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
            $out = '';
            $quoted = false;
            $escaped = false;
            for ($i = 0, $length = strlen($line); $i < $length; $i++) {
                $char = $line[$i];
                if ($quoted) {
                    $out .= $char;
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\') {
                        $escaped = true;
                    } elseif ($char === '"') {
                        $quoted = false;
                    }
                    continue;
                }
                if ($char === '"') {
                    $quoted = true;
                    $out .= $char;
                } elseif ($char === '/' && ($line[$i + 1] ?? '') === '/') {
                    break;
                } else {
                    $out .= $char;
                }
            }
            $clean .= $out . "\n";
        }
        $clean = preg_replace('/,\s*([}\]])/', '$1', $clean);
        $decoded = json_decode($clean);
        return json_last_error() === JSON_ERROR_NONE ? self::arraysKeepingEmptyObjects($decoded) : null;
    }

    /**
     * Converts decoded JSON objects to PHP arrays, except empty objects: they stay `stdClass`, because an
     * empty PHP array would be written back as `[]` and turn every `{}` in an example into a list.
     */
    private static function arraysKeepingEmptyObjects(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value);
            if ($fields === []) {
                return new \stdClass();
            }
            return array_map([self::class, 'arraysKeepingEmptyObjects'], $fields);
        }
        if (is_array($value)) {
            return array_map([self::class, 'arraysKeepingEmptyObjects'], $value);
        }
        return $value;
    }

    private function inferSchema(mixed $value, array $comments = []): array
    {
        if ($value instanceof \stdClass) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                $items = $value ? $this->inferSchema($value[0], $comments) : ['type' => 'string'];
                return ['type' => 'array', 'items' => $items];
            }
            $properties = [];
            foreach ($value as $key => $item) {
                $property = $this->inferSchema($item, $comments);
                if (isset($comments[(string)$key])) {
                    $comment = $comments[(string)$key];
                    $property['description'] = $comment;
                    if (preg_match('/\bemail\b/i', $comment) && $property['type'] === 'string') {
                        $property['format'] = 'email';
                    }
                    if (preg_match('/minimum\s+(\d+)\s+characters?/i', $comment, $minimum) && $property['type'] === 'string') {
                        $property['minLength'] = (int)$minimum[1];
                    }
                    if (preg_match('/maximum\s+(\d+)\s+characters?/i', $comment, $maximum) && $property['type'] === 'string') {
                        $property['maxLength'] = (int)$maximum[1];
                    }
                }
                $properties[$key] = $property;
            }
            return ['type' => 'object', 'properties' => (object)$properties];
        }
        if (is_bool($value)) return ['type' => 'boolean'];
        if (is_int($value)) return ['type' => 'integer'];
        if (is_float($value)) return ['type' => 'number'];
        if ($value === null) return ['type' => 'string', 'nullable' => true];
        return ['type' => 'string'];
    }

    private function jsonComments(string $text): array
    {
        $comments = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
            $quoted = false;
            $escaped = false;
            for ($i = 0, $length = strlen($line); $i < $length - 1; $i++) {
                $char = $line[$i];
                if ($quoted) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\') {
                        $escaped = true;
                    } elseif ($char === '"') {
                        $quoted = false;
                    }
                    continue;
                }
                if ($char === '"') {
                    $quoted = true;
                } elseif ($char === '/' && $line[$i + 1] === '/') {
                    $before = substr($line, 0, $i);
                    if (preg_match('/"([^"]+)"\s*:/', $before, $key)) {
                        $comments[$key[1]] = trim(substr($line, $i + 2));
                    }
                    break;
                }
            }
        }
        return $comments;
    }

    private function toOperation(array $endpoint): array
    {
        $operation = [];
        if (isset($endpoint['aliasOf'])) {
            $operation['x-alias-of'] = $endpoint['aliasOf'];
        }
        if ($endpoint['summary'] !== '') $operation['summary'] = $endpoint['summary'];
        if ($endpoint['description'] !== '') $operation['description'] = $endpoint['description'];
        if ($endpoint['tag'] !== '') $operation['tags'] = [$endpoint['tag']];
        if ($endpoint['permission']) $operation['x-permissions'] = $endpoint['permission'];
        if (!empty($endpoint['permissionAnyOf'])) $operation['x-permissions-any-of'] = $endpoint['permissionAnyOf'];
        $operation['security'] = !empty($endpoint['public']) ? [] : [['BearerAuth' => []]];

        $parameters = $endpoint['request']['parameters'];
        preg_match_all('/\{([^}]+)\}/', $endpoint['path'], $pathParams);
        foreach ($pathParams[1] as $name) {
            $this->addParameter($endpoint, [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ]);
        }
        $parameters = $endpoint['request']['parameters'];
        if ($parameters) {
            $operation['parameters'] = array_map(static function (array $parameter): array {
                $output = [
                    'name' => $parameter['name'],
                    'in' => $parameter['in'],
                    'required' => (bool)$parameter['required'],
                    'schema' => $parameter['schema'],
                ];
                if (isset($parameter['example'])) $output['example'] = $parameter['example'];
                return $output;
            }, $parameters);
        }
        if (isset($endpoint['request']['body'])) {
            $body = $endpoint['request']['body'];
            $operation['requestBody'] = [
                'required' => true,
                'content' => [
                    $body['contentType'] => [
                        'schema' => $body['schema'],
                        ... (array_key_exists('example', $body) ? ['example' => $body['example']] : []),
                    ],
                ],
            ];
        }
        $responses = $endpoint['responses'];
        $hasSuccess = false;
        foreach (array_keys($responses) as $status) {
            if ((int)$status >= 200 && (int)$status < 300) {
                $hasSuccess = true;
                break;
            }
        }
        if (!$hasSuccess) {
            if ($this->listEnvelope !== null && preg_match('/^Paginated\b/i', $endpoint['description'])) {
                $responseValue = $this->responseEnvelope !== null
                    ? array_merge($this->responseEnvelope, ['data' => $this->listEnvelope])
                    : $this->listEnvelope;
                $responses['200'] = [
                    'description' => 'Success',
                    'content' => [
                        'application/json' => [
                            'schema' => $this->inferSchema($responseValue),
                            'example' => $responseValue,
                        ],
                    ],
                ];
            } else {
                $responses['200'] = ['description' => 'Success'];
            }
        }
        ksort($responses);
        $operation['responses'] = (object)$responses;
        return $operation;
    }

    private function codeSpans(string $text): array
    {
        preg_match_all('/`([^`]+)`/', $text, $matches);
        return $matches[1];
    }

    private function stripInline(string $text): string
    {
        $code = [];
        $text = preg_replace_callback('/`([^`]*)`/', static function (array $match) use (&$code): string {
            $key = "\x01" . count($code) . "\x02";
            $code[$key] = $match[1];
            return $key;
        }, $text);
        $text = preg_replace('/\*\*([^*]+)\*\*|__([^_]+)__/', '$1$2', $text);
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)|(?<!_)_([^_]+)_(?!_)/', '$1$2', $text);
        $text = strtr($text, $code);
        return trim($text);
    }

    private function cleanDescription(string $text): string
    {
        $text = $this->stripInline($text);
        $text = preg_replace('/\bAuth\s*:\s*(?:true|false)\b[.!]?/i', '', $text);
        // A bare horizontal rule is structure, never endpoint wording (it otherwise
        // surfaces as summaries like "---" and descriptions ending in "---").
        $text = preg_replace('/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/m', '', $text);
        return trim(preg_replace('/\s{2,}/', ' ', $text));
    }

    private function firstSentence(string $text): string
    {
        if (preg_match('/^(.+?[.!?])(?:\s|$)/', trim($text), $match)) {
            return trim($match[1]);
        }
        return trim($text);
    }

    private function reasonPhrase(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            409 => 'Conflict',
            429 => 'Too Many Requests',
            default => $status >= 500 ? 'Server Error' : 'Response',
        };
    }

    private function warn(string $code, string $message, array $lines, ?array $endpoint = null): void
    {
        $warning = ['code' => $code, 'message' => $message, 'lines' => $lines];
        if ($endpoint !== null) {
            $warning['endpoint'] = $endpoint['method'] . ' ' . $endpoint['path'];
        }
        $this->warnings[] = $warning;
    }

    private function isWithin(?int $sectionId, ?int $ancestorId, array $sections): bool
    {
        if ($ancestorId === null) {
            return false;
        }
        while ($sectionId !== null) {
            if ($sectionId === $ancestorId) {
                return true;
            }
            $sectionId = $sections[$sectionId]['parent'];
        }
        return false;
    }
}
