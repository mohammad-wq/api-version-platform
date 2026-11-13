<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform;

/**
 * Next-version suggestion, ported from the web UI's lib/version-number.js so the dashboard, this CLI and
 * the npm package all suggest the same name: keep the first version's format (prefix, separators, digit
 * groups), take the numbers from the latest version, and bump the last number past any name in use.
 */
final class VersionNumber
{
    /**
     * @param list<string> $oldestFirst version names, oldest first (index 0 sets the format)
     * @return string|null null when there are no versions yet
     */
    public static function suggestNext(array $oldestFirst): ?string
    {
        $existing = array_values(array_filter(array_map(fn ($name) => trim((string) $name), $oldestFirst), fn ($name) => $name !== ''));
        if ($existing === []) {
            return null;
        }

        $format = $existing[0];
        $template = self::tokens($format);
        $latestNumbers = array_values(array_map(
            fn (array $token) => $token['value'],
            array_filter(self::tokens($existing[count($existing) - 1]), fn (array $token) => $token['kind'] === 'num'),
        ));

        // Literals come from the first version, numbers from the latest one.
        $latestIndex = 0;
        $base = [];
        foreach ($template as $token) {
            if ($token['kind'] === 'num' && $latestIndex < count($latestNumbers)) {
                $token['value'] = $latestNumbers[$latestIndex++];
            }
            $base[] = $token;
        }

        $lastNumber = null;
        foreach ($base as $index => $token) {
            if ($token['kind'] === 'num') $lastNumber = $index;
        }
        if ($lastNumber === null) {
            // The first name has no digits (e.g. "beta"): grow a numeric suffix instead.
            $n = count($existing) + 1;
            $candidate = "{$format}-{$n}";
            while (self::taken($candidate, $existing)) $candidate = $format . '-' . ++$n;
            return $candidate;
        }

        do {
            $base[$lastNumber]['value']++;
            $candidate = self::render($base);
        } while (self::taken($candidate, $existing));
        return $candidate;
    }

    /** @return list<array{kind: string, value?: int, width?: int, text?: string}> */
    private static function tokens(string $value): array
    {
        preg_match_all('/(\d+)|(\D+)/', trim($value), $matches, PREG_SET_ORDER);
        return array_map(fn (array $match) => isset($match[2]) && $match[2] !== ''
            ? ['kind' => 'lit', 'text' => $match[2]]
            : ['kind' => 'num', 'value' => (int) $match[1], 'width' => strlen($match[1])], $matches);
    }

    private static function render(array $tokens): string
    {
        return implode('', array_map(fn (array $token) => $token['kind'] === 'num'
            ? str_pad((string) $token['value'], $token['width'] ?? 0, '0', STR_PAD_LEFT)
            : $token['text'], $tokens));
    }

    /** Case-insensitive, as the UI checks it. */
    private static function taken(string $candidate, array $existing): bool
    {
        foreach ($existing as $name) {
            if (strtolower($name) === strtolower($candidate)) return true;
        }
        return false;
    }
}
