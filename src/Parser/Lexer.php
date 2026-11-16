<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform\Parser;

/**
 * Stage 1: turns document text into structure. Strips HTML comments, reads blocks (headings, tables,
 * fenced code, paragraphs) and groups them into a heading tree of sections. Also holds the inline-text
 * helpers (code spans, Markdown emphasis, sentences) the later stages share. Stateless.
 */
final class Lexer
{
    /**
     * Decodes the text (non-UTF-8 input is read as Windows-1252), drops a BOM and splits it into lines.
     *
     * @return array{lines: list<string>, notUtf8: bool}
     */
    public static function split(string $markdown): array
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
        return ['lines' => $lines, 'notUtf8' => $notUtf8];
    }

    /**
     * @param list<string> $lines
     * @return array{sections: list<array<string, mixed>>, title: string, description: string}
     */
    public static function lex(array $lines): array
    {
        return self::buildSections(self::readBlocks(self::hideHtmlComments($lines)));
    }

    /**
     * Groups blocks under the heading that precedes them. Also reports the document title (the first
     * level-1 heading) and description (the first paragraph under it).
     *
     * @return array{sections: list<array<string, mixed>>, title: string, description: string}
     */
    public static function buildSections(array $blocks): array
    {
        $title = 'Untitled API';
        $description = '';
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
                    'title' => self::cleanTitle($block['text']),
                    'heading' => $block,
                    'prefix' => self::prefixFromHeading($block['text']),
                    'blocks' => [],
                ];
                $sections[] = $section;
                $stack[] = $section;
                if ($block['level'] === 1 && $title === 'Untitled API') {
                    $title = $section['title'] ?: $title;
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
                if ($section['level'] === 1 && $block['kind'] === 'paragraph' && $description === '') {
                    $description = self::stripInline($block['text']);
                    break;
                }
            }
            if ($section['prefix'] === null && $section['parent'] !== null) {
                $section['prefix'] = $sections[$section['parent']]['prefix'];
            }
            $section['index'] = $index;
        }
        unset($section);
        return ['sections' => $sections, 'title' => $title, 'description' => $description];
    }

    public static function hideHtmlComments(array $lines): array
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

    public static function readBlocks(array $lines): array
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
                $header = self::splitTableRow($trimmed);
                $rows = [];
                $i += 2;
                while ($i < $count && str_starts_with(trim($lines[$i]), '|')) {
                    $cells = self::splitTableRow(trim($lines[$i]));
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

    public static function splitTableRow(string $line): array
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

    public static function cleanTitle(string $title): string
    {
        $title = preg_replace('/\s*—\s*`[^`]*`.*$/u', '', $title);
        $title = preg_replace('/`[^`]*`|\([^)]*\)/', '', $title);
        return trim(self::stripInline($title));
    }

    public static function prefixFromHeading(string $heading): ?string
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

    public static function codeSpans(string $text): array
    {
        preg_match_all('/`([^`]+)`/', $text, $matches);
        return $matches[1];
    }

    public static function stripInline(string $text): string
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

    public static function cleanDescription(string $text): string
    {
        $text = self::stripInline($text);
        $text = preg_replace('/\bAuth\s*:\s*(?:true|false)\b[.!]?/i', '', $text);
        // A bare horizontal rule is structure, never endpoint wording (it otherwise
        // surfaces as summaries like "---" and descriptions ending in "---").
        $text = preg_replace('/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/m', '', $text);
        return trim(preg_replace('/\s{2,}/', ' ', $text));
    }

    public static function firstSentence(string $text): string
    {
        if (preg_match('/^(.+?[.!?])(?:\s|$)/', trim($text), $match)) {
            return trim($match[1]);
        }
        return trim($text);
    }
}
