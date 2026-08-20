<?php

declare(strict_types=1);

namespace App\Support;

final class PreviewHighlight
{
    private const MAX = 100000;
    private const MAX_ELEMENTS = 8;
    private const MAX_TEXT = 180;
    private const MAX_ATTR = 200;
    private const MAX_SELECTOR = 180;

    /**
     * @param mixed $raw
     * @return array{
     *     path: string,
     *     viewport: array{width: int, height: int},
     *     scroll: array{x: int, y: int},
     *     box: array{x: int, y: int, width: int, height: int},
     *     elements: list<array{tag: string, id: string, class: string, selector: string, text: string, src: string, href: string}>
     * }|null
     */
    public static function fromRequest(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $box = is_array($raw['box'] ?? null) ? $raw['box'] : $raw;
        $x = self::num($box['x'] ?? $box['left'] ?? null);
        $y = self::num($box['y'] ?? $box['top'] ?? null);
        $width = self::num($box['width'] ?? null);
        $height = self::num($box['height'] ?? null);
        if ($width < 1 || $height < 1) {
            return null;
        }
        $viewport = is_array($raw['viewport'] ?? null) ? $raw['viewport'] : [];
        $scroll = is_array($raw['scroll'] ?? null) ? $raw['scroll'] : [];
        $elements = [];
        $seen = [];
        $rawElements = $raw['elements'] ?? [];
        if (is_array($rawElements)) {
            foreach ($rawElements as $row) {
                if (count($elements) >= self::MAX_ELEMENTS) {
                    break;
                }
                if (!is_array($row)) {
                    continue;
                }
                $item = self::element($row);
                if ($item === null) {
                    continue;
                }
                $key = $item['selector'] !== '' ? $item['selector'] : $item['tag'] . '|' . $item['text'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $elements[] = $item;
            }
        }

        return [
            'path' => self::path((string) ($raw['path'] ?? '')),
            'viewport' => [
                'width' => self::num($viewport['width'] ?? null),
                'height' => self::num($viewport['height'] ?? null),
            ],
            'scroll' => [
                'x' => self::num($scroll['x'] ?? null),
                'y' => self::num($scroll['y'] ?? null),
            ],
            'box' => [
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'height' => $height,
            ],
            'elements' => $elements,
        ];
    }

    /**
     * @param array{
     *     path: string,
     *     viewport: array{width: int, height: int},
     *     scroll: array{x: int, y: int},
     *     box: array{x: int, y: int, width: int, height: int},
     *     elements: list<array{tag: string, id: string, class: string, selector: string, text: string, src: string, href: string}>
     * } $highlight
     */
    public static function prompt(array $highlight): string
    {
        $path = $highlight['path'] !== '' ? $highlight['path'] : 'the current preview page';
        $box = $highlight['box'];
        $viewport = $highlight['viewport'];
        $scroll = $highlight['scroll'];
        $lines = [
            'The owner drew a box on the preview to mark an area. If they say "this", "here", "this section", or "this part", they mean that highlighted area unless they name another.',
            sprintf(
                'Box on %s: x=%d, y=%d, width=%d, height=%d (pixels from the top-left of the page, including scroll). Viewport %d×%d, scroll x=%d y=%d.',
                $path,
                $box['x'],
                $box['y'],
                $box['width'],
                $box['height'],
                $viewport['width'],
                $viewport['height'],
                $scroll['x'],
                $scroll['y'],
            ),
        ];
        if ($highlight['elements'] === []) {
            $lines[] = 'No distinct HTML elements were identified in the box; use the coordinates on that preview page.';

            return implode("\n", $lines);
        }
        $lines[] = 'HTML in that box:';
        foreach ($highlight['elements'] as $el) {
            $label = $el['selector'] !== '' ? $el['selector'] : ($el['tag'] !== '' ? $el['tag'] : 'element');
            $bits = [];
            if ($el['text'] !== '') {
                $bits[] = '"' . $el['text'] . '"';
            }
            if ($el['src'] !== '') {
                $bits[] = 'src ' . $el['src'];
            }
            if ($el['href'] !== '') {
                $bits[] = 'href ' . $el['href'];
            }
            $lines[] = '- `' . $label . '`' . ($bits === [] ? '' : ' — ' . implode(', ', $bits));
        }

        return implode("\n", $lines);
    }

    private static function num(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }
        $n = (int) round((float) $value);
        if ($n < 0) {
            return 0;
        }
        if ($n > self::MAX) {
            return self::MAX;
        }

        return $n;
    }

    private static function path(string $raw): string
    {
        $raw = trim(str_replace('\\', '/', $raw));
        if ($raw === '' || str_contains($raw, '..') || strlen($raw) > 300) {
            return '';
        }
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return '';
        }
        if (!str_starts_with($raw, '/')) {
            $raw = '/' . $raw;
        }
        if (preg_match('/[^A-Za-z0-9._\/?=&%#\-]/', $raw) === 1) {
            return '';
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{tag: string, id: string, class: string, selector: string, text: string, src: string, href: string}|null
     */
    private static function element(array $row): ?array
    {
        $tag = self::token((string) ($row['tag'] ?? ''), 32, '/[^A-Za-z0-9]/');
        $id = self::clip((string) ($row['id'] ?? ''), self::MAX_ATTR);
        $class = self::clip((string) ($row['class'] ?? ''), self::MAX_ATTR);
        $selector = self::selector((string) ($row['selector'] ?? ''));
        $text = self::clip((string) ($row['text'] ?? ''), self::MAX_TEXT);
        $src = self::attrPath((string) ($row['src'] ?? ''));
        $href = self::attrPath((string) ($row['href'] ?? ''));
        if ($tag === '' && $selector === '' && $text === '' && $src === '') {
            return null;
        }

        return [
            'tag' => $tag,
            'id' => $id,
            'class' => $class,
            'selector' => $selector,
            'text' => $text,
            'src' => $src,
            'href' => $href,
        ];
    }

    private static function selector(string $raw): string
    {
        $raw = self::clip($raw, self::MAX_SELECTOR);
        if ($raw === '' || preg_match('/[^A-Za-z0-9_#.:\[\]=()>\~\+\s\-*]/', $raw) === 1) {
            return '';
        }

        return $raw;
    }

    private static function attrPath(string $raw): string
    {
        $raw = trim(str_replace('\\', '/', $raw));
        if ($raw === '' || str_contains($raw, '..') || strlen($raw) > self::MAX_ATTR) {
            return '';
        }
        if (str_contains($raw, ':') && !str_starts_with($raw, '/') && !preg_match('/^[A-Za-z0-9._-]+\\//', $raw)) {
            return '';
        }
        if (preg_match('/[^A-Za-z0-9._\/?=&%#\-]/', $raw) === 1) {
            return '';
        }

        return $raw;
    }

    private static function token(string $raw, int $max, string $strip): string
    {
        $raw = strtolower(trim($raw));
        $raw = preg_replace($strip, '', $raw) ?? '';

        return self::clip($raw, $max);
    }

    private static function clip(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '' || $max < 2) {
            return $text;
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1) . '…';
    }
}
