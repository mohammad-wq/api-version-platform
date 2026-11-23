<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform\Parser;

/**
 * Collects what the parser found wrong with a document. Lenient (legacy) documents only get warnings;
 * in a strict document (front-matter `avp: 1`) the structural problems in STRICT_ERRORS are errors.
 */
final class Diagnostics
{
    /** Problems the lenient parser tolerates with a warning, but a strict document must not contain. */
    public const STRICT_ERRORS = [
        'NOT_UTF8',
        'NO_ENDPOINTS_FOUND',
        'INVALID_TABLE_ROW',
        'INVALID_METHOD',
        'MISSING_ENDPOINT_PATH',
        'UNRESOLVED_PREFIX',
        'UNRESOLVED_PUBLIC_ENDPOINT',
        'DUPLICATE_ENDPOINT',
        'DOT_SEGMENT_PATH',
        'INVALID_SERVER',
        'UNRECOGNIZED_COLUMN',
        'TABLE_ROW_TOO_WIDE',
        'UNBOUND_JSON_BLOCK',
        'INVALID_JSON_BLOCK',
    ];

    /** @var list<array<string, mixed>> */
    private array $warnings = [];
    /** @var list<array<string, mixed>> */
    private array $errors = [];

    /** True for a document with an `avp: 1` header: structural problems are listed as errors. */
    public bool $strict;

    public function __construct(bool $strict = false)
    {
        $this->strict = $strict;
    }

    /** A warning; in a strict document, a code listed in STRICT_ERRORS becomes an error. */
    public function warn(string $code, string $message, array $lines, ?array $endpoint = null): void
    {
        $this->add($this->strict && in_array($code, self::STRICT_ERRORS, true), $code, $message, $lines, $endpoint);
    }

    public function error(string $code, string $message, array $lines, ?array $endpoint = null): void
    {
        $this->add(true, $code, $message, $lines, $endpoint);
    }

    /** @return list<array<string, mixed>> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<array<string, mixed>> */
    public function errors(): array
    {
        return $this->errors;
    }

    private function add(bool $isError, string $code, string $message, array $lines, ?array $endpoint): void
    {
        $entry = ['code' => $code, 'message' => $message, 'lines' => $lines];
        if ($endpoint !== null) {
            $entry['endpoint'] = $endpoint['method'] . ' ' . $endpoint['path'];
        }
        if ($isError) {
            $this->errors[] = $entry;
        } else {
            $this->warnings[] = $entry;
        }
    }
}
