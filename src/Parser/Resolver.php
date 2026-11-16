<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform\Parser;

/**
 * Stage 2: reads the sections and decides what the API is. Conventions and servers, path prefixes,
 * endpoint tables and headings, the detail paragraphs and JSON examples bound to each endpoint,
 * permissions and auth markers. Collects endpoints in plain arrays; the OpenApiBuilder renders them.
 */
final class Resolver
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var list<array<string, mixed>> */
    public array $endpoints = [];
    /** @var list<array<string, mixed>> */
    public array $servers = [];
    /** @var array<string, mixed>|null */
    public ?array $responseEnvelope = null;
    /** @var array<string, mixed>|null */
    public ?array $listEnvelope = null;

    private array $paginationParameters = [];
    private array $publicTargets = [];
    private array $sectionsForTag = [];

    public function __construct(private Diagnostics $diagnostics)
    {
    }

    /** @param list<array<string, mixed>> $sections from Lexer::lex() */
    public function resolve(array $sections): void
    {
        $this->sectionsForTag = $sections;
        $this->readConventions($sections);
        $this->readTables($sections);
        $this->readEndpointHeadings($sections);
        $this->bindEndpointDetails($sections);
        $this->attachTableNotes($sections);
        $this->applyPublicTargets();
    }

    private function warn(string $code, string $message, array $lines, ?array $endpoint = null): void
    {
        $this->diagnostics->warn($code, $message, $lines, $endpoint);
    }

    /** Which problem a table row that is not an endpoint has (strict documents tell the cases apart). */
    private function rowProblemCode(array $methods, string $target, string $methodCell): string
    {
        if ($this->diagnostics->strict) {
            if (!$methods && trim($methodCell) !== '') {
                return 'INVALID_METHOD';
            }
            if ($methods && $target === '') {
                return 'MISSING_ENDPOINT_PATH';
            }
        }
        return 'INVALID_TABLE_ROW';
    }

    private function readConventions(array $sections): void
    {
        foreach ($sections as $section) {
            foreach ($section['blocks'] as $index => $block) {
                if ($block['kind'] === 'fence' && ($block['lang'] === '' || $block['lang'] === 'json')) {
                    $value = OpenApiBuilder::decodeJsonExample($block['text']);
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
                    $canonical = self::normalizeServerUrl($url);
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
            $canonical = self::normalizeServerUrl($nameAndUrl[2]);
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
                        $loginUrl = self::normalizeServerUrl($value);
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
    public static function normalizeServerUrl(string $url): ?string
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
            $target = Lexer::codeSpans($targetCell)[0] ?? Lexer::stripInline($targetCell);
            if (!$methods || $target === '') {
                $this->warn($this->rowProblemCode($methods, $target, $cells[$columns['method']] ?? ''), 'Row is not an endpoint: ' . Lexer::stripInline(implode(' | ', $cells)), [$row['line'], $row['line']]);
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
                    $endpoint['description'] = Lexer::cleanDescription($description);
                    // Every parameter source funnels through addParameter(), which dedupes by
                    // name+in — otherwise `?search=` in the path template and a `?search=` mention
                    // in the description each end up as their own parameter.
                    foreach ($this->queryTemplateParameters($query, $row['line']) as $parameter) {
                        OpenApiBuilder::addParameter($endpoint, $parameter);
                    }
                    foreach ($this->parseMentionedQueryParams($description, [$row['line'], $row['line']]) as $parameter) {
                        OpenApiBuilder::addParameter($endpoint, $parameter);
                    }
                    if (preg_match('/^Paginated\b/i', Lexer::stripInline($description))) {
                        foreach ($this->paginationParameters as $parameter) {
                            OpenApiBuilder::addParameter($endpoint, $parameter);
                        }
                    }
                    foreach ($this->parseHeaderParameters($description, [$row['line'], $row['line']]) as $parameter) {
                        OpenApiBuilder::addParameter($endpoint, $parameter);
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
                        OpenApiBuilder::addParameter($alias, $parameter);
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
            $entity = Lexer::stripInline($row['cells'][$entityColumn] ?? '');
            $permissions = $this->parsePermissions($row['cells'][$permissionsColumn] ?? '');
            $auth = $authColumn === false ? null : $this->authValue($row['cells'][$authColumn] ?? '');
            foreach ($headers as $index => $header) {
                if (!preg_match('#\(([a-z/ ]+)\)\s*$#i', $header, $match)) {
                    continue;
                }
                $methods = $this->parseMethods($match[1]);
                $target = Lexer::codeSpans($row['cells'][$index] ?? '')[0] ?? '';
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
                        'schema' => OpenApiBuilder::shorthandSchema($match[3]),
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
                        $note = Lexer::cleanDescription($text);
                        if ($note !== '' && !str_contains($this->endpoints[$target]['description'], $note)) {
                            $this->endpoints[$target]['description'] = trim($this->endpoints[$target]['description'] . "\n\n" . $note);
                            if ($this->endpoints[$target]['summary'] === '') {
                                $this->endpoints[$target]['summary'] = Lexer::firstSentence($note);
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
                $value = OpenApiBuilder::decodeJsonExample($section['blocks'][$i + 1]['text']);
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
                            'schema' => OpenApiBuilder::inferSchema($value, OpenApiBuilder::jsonComments($section['blocks'][$i + 1]['text'])),
                            'example' => $value,
                        ];
                    } else {
                        $wrapped = stripos($label, '`data`') !== false;
                        $responseValue = $wrapped && $this->responseEnvelope !== null
                            ? array_merge($this->responseEnvelope, ['data' => $value])
                            : $value;
                        $this->endpoints[$target]['responses'][$status] = [
                            'description' => $status >= 400 ? OpenApiBuilder::reasonPhrase($status) : OpenApiBuilder::reasonPhrase($status),
                            'content' => [
                                'application/json' => [
                                'schema' => OpenApiBuilder::inferSchema($responseValue, OpenApiBuilder::jsonComments($section['blocks'][$i + 1]['text'])),
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
                    $text = Lexer::stripInline($section['blocks'][$next]['text']);
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
            'summary' => Lexer::firstSentence(Lexer::cleanDescription($description)),
            'description' => Lexer::cleanDescription($description),
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
            OpenApiBuilder::addParameter($endpoint, $parameter);
        }
        foreach ($this->parseHeaderParameters($text, [$endpoint['line'], $endpoint['line']]) as $parameter) {
            OpenApiBuilder::addParameter($endpoint, $parameter);
        }
        if (preg_match_all('/(?:\*\*)?requires(?:\*\*)?\s+`([A-Za-z_][A-Za-z0-9_]*)`/i', $text, $required)) {
            foreach ($required[1] as $name) {
                OpenApiBuilder::addParameter($endpoint, [
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
                    $endpoint['responses'][$status] = ['description' => trim($match[2]) ?: OpenApiBuilder::reasonPhrase($status)];
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
                    'description' => trim($mention[2]) ?: OpenApiBuilder::reasonPhrase($status),
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
                OpenApiBuilder::addParameter($endpoint, $parameter);
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

    private function parseMethods(string $text): array
    {
        preg_match_all('/GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS/i', Lexer::stripInline($text), $matches);
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
                'schema' => OpenApiBuilder::querySchema($value),
                'example' => OpenApiBuilder::queryExample($value),
            ];
        }
        return $parameters;
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
                $schema = OpenApiBuilder::querySchema($value);
                if (preg_match('/\(([^)]*)\)/', substr($text, strpos($text, $match[0]) + strlen($match[0])), $details)) {
                    if (preg_match('/default\s+`?([\w.-]+)`?/i', $details[1], $default)) {
                        $schema['default'] = OpenApiBuilder::queryExample($default[1]);
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
                    $schema['default'] = OpenApiBuilder::queryExample($default[1]);
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

    private function authValue(string $text): ?bool
    {
        $text = trim(Lexer::stripInline($text));
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
            array_map('trim', Lexer::codeSpans($text)),
            static fn(string $code): bool => preg_match('/^[A-Za-z0-9_*.-]+:[A-Za-z0-9_*.-]+$/', $code) === 1,
        ));
        $codes = array_values(array_unique($codes));
        return count($codes) > 1 ? $codes : [];
    }

    private function applyShorthand(array &$endpoint, string $cell): void
    {
        $spans = Lexer::codeSpans($cell);
        foreach (array_slice($spans, 1) as $span) {
            if (str_starts_with($span, '{') && str_ends_with($span, '}')) {
                $endpoint['request']['body'] = [
                    'contentType' => 'application/json',
                    'schema' => OpenApiBuilder::shorthandSchema($span),
                ];
                return;
            }
        }
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
