<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform;

/** Produces one stable representation of a Markdown working copy. */
final class SourceHasher
{
    public static function normalize(string $markdown): string
    {
        $markdown = self::toUtf8($markdown);
        $markdown = preg_replace('/^\xEF\xBB\xBF/', '', $markdown) ?? $markdown;
        return str_replace(["\r\n", "\r"], "\n", $markdown);
    }

    public static function hash(string $markdown): string
    {
        return hash('sha256', self::normalize($markdown));
    }

    public static function isUtf8(string $text): bool
    {
        return preg_match('//u', $text) === 1;
    }

    /** Text that is not valid UTF-8 is read as Windows-1252 (what Windows editors save as "ANSI"). */
    public static function toUtf8(string $text): string
    {
        if (self::isUtf8($text)) {
            return $text;
        }
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            if (is_string($converted) && self::isUtf8($converted)) return $converted;
        }
        // Without mbstring: ISO-8859-1 to UTF-8, byte by byte.
        return (string) preg_replace_callback('/[\x80-\xFF]/', static fn (array $m): string => chr(0xC0 | (ord($m[0]) >> 6)) . chr(0x80 | (ord($m[0]) & 0x3F)), $text);
    }
}
