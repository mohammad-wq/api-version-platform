<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform;

use Mohammad\ApiVersionPlatform\Parser\DocumentParser;

/**
 * Converts the API document (Markdown) into an OpenAPI 3.0.3 document.
 *
 * A thin entry point over src/Parser/: Lexer (text -> sections), Resolver (sections -> endpoints) and
 * OpenApiBuilder (endpoints -> OpenAPI). See docs/FORMAT.md for the document format.
 */
final class MarkdownOpenApiParser
{
    /**
     * Parses the document. Problems never block anything: in a strict document (`avp: 1` header) the structural
     * ones are reported as warnings (first, in document order) instead of being guessed at silently, so a version
     * can always be saved. Use Parser\Validator::check() when you need the errors listed separately.
     *
     * @return array{
     *   openapi: array<string, mixed>,
     *   warnings: list<array<string, mixed>>,
     *   statistics: array{endpoints: int, methods: array<string, int>, tags: int},
     *   errors: list<array<string, mixed>>
     * } `errors` is always [] here; see DocumentParser::parse() for the strict result.
     */
    public static function parse(string $markdown): array
    {
        $result = DocumentParser::parse($markdown);
        if ($result['errors'] !== []) {
            $result['warnings'] = array_merge($result['errors'], $result['warnings']);
            $result['errors'] = [];
        }
        return $result;
    }
}
