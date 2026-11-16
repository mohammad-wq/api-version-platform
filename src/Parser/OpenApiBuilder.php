<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform\Parser;

/**
 * Stage 3: turns resolved endpoints into the OpenAPI 3.0.3 document. Also holds the schema helpers
 * (JSON example inference, `{a, b?}` shorthand, query-parameter schemas) the resolver reuses.
 */
final class OpenApiBuilder
{
    /**
     * @param array<string, mixed>|null $responseEnvelope the document's `{statusCode, data}` convention, when it showed one
     * @param array<string, mixed>|null $listEnvelope the `{items, pagination}` convention, when it showed one
     */
    public function __construct(private ?array $responseEnvelope = null, private ?array $listEnvelope = null)
    {
    }

    /**
     * @param list<array<string, mixed>> $endpoints
     * @param array{title: string, description: string, version: string, servers: list<array<string, mixed>>, auth: string} $meta
     * @return array{openapi: array<string, mixed>, methods: array<string, int>, tags: int}
     */
    public function build(array $endpoints, array $meta, Diagnostics $diagnostics): array
    {
        $paths = [];
        $methods = [];
        $tags = [];
        $usedOperationIds = [];
        foreach ($endpoints as $endpoint) {
            $operation = $this->toOperation($endpoint);
            $operation['operationId'] = self::operationId($endpoint['method'], $endpoint['path'], $usedOperationIds);
            $method = strtolower($endpoint['method']);
            if (!isset($paths[$endpoint['path']]) && preg_match('#(^|/)\.{1,2}(/|$)#', $endpoint['path'])) {
                $diagnostics->warn(
                    'DOT_SEGMENT_PATH',
                    "{$endpoint['path']} contains a \".\" or \"..\" segment; clients may resolve it to a different URL.",
                    [$endpoint['line'] ?? 1, $endpoint['line'] ?? 1],
                    $endpoint,
                );
            }
            // OpenAPI keys operations by path+method, so a repeated table row would silently
            // overwrite the earlier one here and lose its detail. Say so instead.
            if (isset($paths[$endpoint['path']][$method])) {
                $diagnostics->warn(
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
                'title' => $meta['title'],
                'version' => $meta['version'],
            ],
            'paths' => (object)$paths,
        ];
        if ($meta['auth'] !== 'none') {
            $openapi['components'] = [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                    ],
                ],
            ];
        }
        if ($meta['description'] !== '') {
            $openapi['info']['description'] = $meta['description'];
        }
        if ($meta['servers']) {
            $openapi['servers'] = array_values($meta['servers']);
        }
        if ($tags) {
            $openapi['tags'] = array_map(
                static fn(string $name): array => ['name' => $name],
                array_keys($tags)
            );
        }
        return ['openapi' => $openapi, 'methods' => $methods, 'tags' => count($tags)];
    }

    public static function querySchema(string $value): array
    {
        if (strcasecmp($value, 'YYYY-MM-DD') === 0) return ['type' => 'string', 'format' => 'date'];
        if (preg_match('/^-?\d+$/', $value)) return ['type' => 'integer'];
        if (preg_match('/^-?\d+\.\d+$/', $value)) return ['type' => 'number'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return ['type' => 'string', 'format' => 'date'];
        if (str_contains($value, '|')) return ['type' => 'string', 'enum' => explode('|', $value)];
        return ['type' => 'string'];
    }

    public static function queryExample(string $value): mixed
    {
        if (preg_match('/^-?\d+$/', $value)) return (int)$value;
        if (preg_match('/^-?\d+\.\d+$/', $value)) return (float)$value;
        return $value === '' ? null : $value;
    }

    public static function addParameter(array &$endpoint, array $parameter): void
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

    public static function shorthandSchema(string $text): array
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
                $properties[$field[1]] = self::shorthandValueSchema(trim($field[3] ?? ''));
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
    public static function shorthandValueSchema(string $value): array
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

    public static function decodeJsonExample(string $text): mixed
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

    public static function inferSchema(mixed $value, array $comments = []): array
    {
        if ($value instanceof \stdClass) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }
        if (is_array($value)) {
            if (($value === [] || array_keys($value) === range(0, count($value) - 1))) {
                $items = $value ? self::inferSchema($value[0], $comments) : ['type' => 'string'];
                return ['type' => 'array', 'items' => $items];
            }
            $properties = [];
            foreach ($value as $key => $item) {
                $property = self::inferSchema($item, $comments);
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

    public static function jsonComments(string $text): array
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

    public static function reasonPhrase(int $status): string
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

    /** Every operation gets a unique operationId derived from its method and path (`get_widgets_list_php`, `_2` on collisions). */
    public static function operationId(string $method, string $path, array &$used): string
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

    public function toOperation(array $endpoint): array
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
            self::addParameter($endpoint, [
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
                            'schema' => self::inferSchema($responseValue),
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
}
