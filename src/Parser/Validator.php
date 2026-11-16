<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform\Parser;

/** Checks an API document without saving anything (`avp check`). */
final class Validator
{
    /**
     * @return array{
     *   ok: bool,
     *   strict: bool,
     *   errors: list<array<string, mixed>>,
     *   warnings: list<array<string, mixed>>,
     *   statistics: array{endpoints: int, methods: array<string, int>, tags: int}
     * }
     *   `strict` is true for a document with an `avp: 1` header. `ok` is true when there are no errors
     *   (a legacy document never has errors, only warnings such as LEGACY_FORMAT).
     */
    public static function check(string $markdown): array
    {
        $result = DocumentParser::parse($markdown);
        return [
            'ok' => $result['errors'] === [],
            'strict' => FrontMatter::extract(Lexer::split($markdown)['lines'])['present'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'statistics' => $result['statistics'],
        ];
    }
}
