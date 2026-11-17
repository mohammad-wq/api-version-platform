<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform\Parser;

/**
 * Runs the three stages on one document: Lexer (text -> sections), Resolver (sections -> endpoints),
 * OpenApiBuilder (endpoints -> OpenAPI). A document with an `avp: 1` front-matter header is strict:
 * structural problems are reported in `errors`. Any other document is parsed leniently, as before,
 * and gets a LEGACY_FORMAT warning suggesting `avp migrate`.
 */
final class DocumentParser
{
    /**
     * @return array{
     *   openapi: array<string, mixed>,
     *   warnings: list<array<string, mixed>>,
     *   statistics: array{endpoints: int, methods: array<string, int>, tags: int},
     *   errors: list<array<string, mixed>>
     * }
     */
    public static function parse(string $markdown): array
    {
        $split = Lexer::split($markdown);
        $lines = $split['lines'];
        $front = FrontMatter::extract($lines);
        $strict = $front['present'];
        $data = $front['data'];
        $diagnostics = new Diagnostics($strict);
        foreach ($front['errors'] as $error) {
            $diagnostics->error($error['code'], $error['message'], $error['lines']);
        }
        if ($split['notUtf8']) {
            $diagnostics->warn(
                'NOT_UTF8',
                'The document is not valid UTF-8, so it was read as Windows-1252. Save it as UTF-8 to be sure every character is kept.',
                [1, 1],
            );
        }

        $lexed = Lexer::lex($front['lines']);
        $resolver = new Resolver($diagnostics);
        $resolver->resolve($lexed['sections']);

        if (!$resolver->endpoints) {
            $diagnostics->warn(
                'NO_ENDPOINTS_FOUND',
                'No API endpoints were detected. Check the Markdown table headers and endpoint paths.',
                [1, max(1, count($lines))],
            );
        }

        $auth = (string) ($data['auth'] ?? 'bearer');
        if ($auth === 'none') {
            foreach ($resolver->endpoints as &$endpoint) {
                $endpoint['public'] = true;
            }
            unset($endpoint);
        }
        $version = isset($data['version']) && preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,49}$/', (string) $data['version'])
            ? (string) $data['version']
            : '1.0.0';
        $title = isset($data['title']) && is_string($data['title']) && trim($data['title']) !== '' ? trim($data['title']) : $lexed['title'];
        $description = isset($data['description']) && is_string($data['description']) ? trim($data['description']) : $lexed['description'];

        $builder = new OpenApiBuilder($resolver->responseEnvelope, $resolver->listEnvelope);
        $built = $builder->build($resolver->endpoints, [
            'title' => $title,
            'description' => $description,
            'version' => $version,
            'servers' => self::servers($data, $resolver->servers, $diagnostics),
            'auth' => in_array($auth, FrontMatter::AUTH_MODES, true) ? $auth : 'bearer',
        ], $diagnostics);

        if (!$strict) {
            $diagnostics->warn(
                'LEGACY_FORMAT',
                'This document has no front-matter header, so it is read leniently. Run `avp migrate` to add one (---, avp: 1, title: ..., ---) and get strict validation.',
                [1, 1],
            );
        }

        return [
            'openapi' => $built['openapi'],
            'warnings' => $diagnostics->warnings(),
            'statistics' => [
                'endpoints' => count($resolver->endpoints),
                'methods' => $built['methods'],
                'tags' => $built['tags'],
            ],
            'errors' => $diagnostics->errors(),
        ];
    }

    /**
     * The header's `servers` come first. A `Servers:` / `Environments:` line in the body for the same URL
     * adds its details (name, login settings) to that entry; other body servers follow.
     *
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $bodyServers
     * @return list<array<string, mixed>>
     */
    private static function servers(array $data, array $bodyServers, Diagnostics $diagnostics): array
    {
        $preset = [];
        foreach ($data['servers'] ?? [] as $url) {
            $canonical = Resolver::normalizeServerUrl((string) $url);
            $line = (int) ($data['servers_line'] ?? 1);
            if ($canonical === null) {
                $diagnostics->error('INVALID_SERVER', "Invalid server URL \"{$url}\" in the front-matter header; use an HTTP(S) URL without credentials, query, or fragment.", [$line, $line]);
            } elseif (!in_array($canonical, $preset, true)) {
                $preset[] = $canonical;
            }
        }
        if (!$preset) {
            return $bodyServers;
        }
        $servers = [];
        foreach ($preset as $url) {
            $matches = array_values(array_filter($bodyServers, static fn(array $server): bool => $server['url'] === $url));
            foreach ($matches ?: [['url' => $url]] as $server) {
                $servers[] = $server;
            }
        }
        foreach ($bodyServers as $server) {
            if (!in_array($server['url'], $preset, true)) {
                $servers[] = $server;
            }
        }
        return $servers;
    }
}
