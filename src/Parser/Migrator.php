<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform\Parser;

/** Upgrades a legacy API document to the strict format by adding the front-matter header (`avp migrate`). */
final class Migrator
{
    /**
     * Prepends `---`, `avp: 1`, `title:` (the first heading) and the `servers:` the document already
     * declares, then `---`. The body is left exactly as it was, and the line endings and BOM are kept.
     * A document that already has the header is returned unchanged, so running it twice is harmless.
     *
     * Reading it back, the migrated document produces the same OpenAPI as before (plus `version: 1.0.0`
     * is implied); it is also checked strictly now, so problems the lenient parser let through can appear
     * as errors (see `avp check`).
     */
    public static function migrate(string $markdown): string
    {
        $bom = str_starts_with($markdown, "\xEF\xBB\xBF") ? "\xEF\xBB\xBF" : '';
        $body = $bom === '' ? $markdown : substr($markdown, 3);
        if (FrontMatter::extract(Lexer::split($body)['lines'])['present']) {
            return $markdown;
        }
        $parsed = DocumentParser::parse($body);
        $info = $parsed['openapi']['info'];
        $urls = array_values(array_unique(array_map(
            static fn(array $server): string => $server['url'],
            $parsed['openapi']['servers'] ?? [],
        )));

        $eol = str_contains($body, "\r\n") ? "\r\n" : "\n";
        $header = ['---', 'avp: ' . FrontMatter::FORMAT_VERSION, 'title: ' . self::scalar((string) $info['title'])];
        if ($urls) {
            $header[] = 'servers:';
            foreach ($urls as $url) {
                $header[] = '  - ' . self::scalar($url);
            }
        }
        $header[] = '---';
        return $bom . implode($eol, $header) . $eol . $eol . $body;
    }

    /** A header value: bare when that reads back unchanged, otherwise a double-quoted JSON string. */
    private static function scalar(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value));
        $plain = $value !== ''
            && preg_match('/^[A-Za-z0-9_\/(]/', $value) === 1
            && preg_match('/:\s|\s#|:$|\s$|[\x00-\x1F]/', $value) !== 1
            && !in_array(strtolower($value), ['true', 'false', 'null', '~'], true);
        return $plain ? $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
