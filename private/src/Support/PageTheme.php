<?php

declare(strict_types=1);

namespace App\Support;

final class PageTheme
{
    /**
     * @param list<string> $stylesheets
     * @return array<string, mixed>
     */
    public static function fromHtml(string $html, string $url, array $stylesheets = []): array
    {
        $css = implode("\n", $stylesheets);
        foreach (self::styleBlocks($html) as $block) {
            $css .= "\n" . $block;
        }
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) === 1) {
            $title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $tokens = self::tokens($css . "\n" . $html);

        return [
            'url' => $url,
            'title' => $title,
            'colours' => $tokens['colours'],
            'css_variables' => $tokens['variables'],
            'fonts' => $tokens['fonts'],
            'header' => self::region($html, 'header'),
            'footer' => self::region($html, 'footer'),
            'note' => 'Recreate this look in staging css/site.css with edit_file or write_file. Do not copy vendor CSS/JS into the draft.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromCss(string $css, string $url): array
    {
        $tokens = self::tokens($css);

        return [
            'url' => $url,
            'title' => basename((string) (parse_url($url, PHP_URL_PATH) ?: 'stylesheet')),
            'colours' => $tokens['colours'],
            'css_variables' => $tokens['variables'],
            'fonts' => $tokens['fonts'],
            'header' => null,
            'footer' => null,
            'note' => 'Stylesheet tokens only. Recreate the look in css/site.css. Do not copy this file into the draft.',
        ];
    }

    /**
     * @return list<string>
     */
    public static function styleBlocks(string $html): array
    {
        $out = [];
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $m) > 0) {
            foreach ($m[1] as $block) {
                if (is_string($block) && trim($block) !== '') {
                    $out[] = $block;
                }
            }
        }

        return $out;
    }

    /**
     * @return array{colours: list<string>, variables: list<array{name: string, value: string}>, fonts: list<string>}
     */
    public static function tokens(string $css): array
    {
        $counts = [];
        if (preg_match_all('/#([0-9a-fA-F]{3,8})\b/', $css, $hexes) > 0) {
            foreach ($hexes[0] as $raw) {
                $norm = self::normalizeHex($raw);
                if ($norm === null) {
                    continue;
                }
                $counts[$norm] = ($counts[$norm] ?? 0) + 1;
            }
        }
        if (preg_match_all('/rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*[\d.]+)?\s*\)/i', $css, $rgbs, PREG_SET_ORDER) > 0) {
            foreach ($rgbs as $row) {
                $hex = self::rgbToHex((int) $row[1], (int) $row[2], (int) $row[3]);
                if ($hex === null) {
                    continue;
                }
                $counts[$hex] = ($counts[$hex] ?? 0) + 1;
            }
        }
        arsort($counts);
        $colours = array_slice(array_keys($counts), 0, 24);

        $variables = [];
        $seenVar = [];
        if (preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;]+)/', $css, $vars, PREG_SET_ORDER) > 0) {
            foreach ($vars as $row) {
                $name = $row[1];
                if (isset($seenVar[$name])) {
                    continue;
                }
                $value = trim(preg_replace('/\s+/', ' ', $row[2]) ?? $row[2]);
                if (mb_strlen($value) > 80) {
                    $value = mb_substr($value, 0, 79) . '…';
                }
                $seenVar[$name] = true;
                $variables[] = ['name' => $name, 'value' => $value];
                if (count($variables) >= 20) {
                    break;
                }
            }
        }

        $fonts = [];
        $seenFont = [];
        if (preg_match_all('/font-family\s*:\s*([^;}{]+)/i', $css, $families) > 0) {
            foreach ($families[1] as $family) {
                $family = trim(html_entity_decode($family, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $family = trim($family, " \t\"'");
                if ($family === '' || isset($seenFont[strtolower($family)])) {
                    continue;
                }
                $seenFont[strtolower($family)] = true;
                $fonts[] = mb_strlen($family) > 80 ? mb_substr($family, 0, 79) . '…' : $family;
                if (count($fonts) >= 8) {
                    break;
                }
            }
        }

        return [
            'colours' => array_values($colours),
            'variables' => $variables,
            'fonts' => $fonts,
        ];
    }

    /**
     * @return array{tag: string, classes: string, links: list<array{text: string, href: string}>, images: list<array{alt: string, src: string}>, text: string}|null
     */
    public static function region(string $html, string $kind): ?array
    {
        $block = self::regionHtml($html, $kind);
        if ($block === null) {
            return null;
        }
        $tag = 'div';
        $classes = '';
        if (preg_match('/<([a-zA-Z0-9]+)\b([^>]*)>/', $block, $open) === 1) {
            $tag = strtolower($open[1]);
            if (preg_match('/class\s*=\s*["\']([^"\']*)["\']/', $open[2], $cm) === 1) {
                $classes = trim($cm[1]);
            }
        }
        $links = [];
        if (preg_match_all('/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $block, $lm, PREG_SET_ORDER) > 0) {
            foreach ($lm as $row) {
                $href = trim(html_entity_decode($row[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $text = trim(html_entity_decode(strip_tags($row[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
                if ($text === '') {
                    $text = $href;
                }
                $links[] = [
                    'text' => mb_strlen($text) > 60 ? mb_substr($text, 0, 59) . '…' : $text,
                    'href' => mb_strlen($href) > 120 ? mb_substr($href, 0, 119) . '…' : $href,
                ];
                if (count($links) >= 12) {
                    break;
                }
            }
        }
        $images = [];
        if (preg_match_all('/<img\s[^>]*>/i', $block, $im) > 0) {
            foreach ($im[0] as $tagHtml) {
                $alt = '';
                $src = '';
                if (preg_match('/alt\s*=\s*["\']([^"\']*)["\']/', $tagHtml, $am) === 1) {
                    $alt = html_entity_decode($am[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                if (preg_match('/src\s*=\s*["\']([^"\']+)["\']/', $tagHtml, $sm) === 1) {
                    $src = basename((string) (parse_url(html_entity_decode($sm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_PATH) ?: $sm[1]));
                }
                $images[] = ['alt' => $alt, 'src' => $src];
                if (count($images) >= 6) {
                    break;
                }
            }
        }
        $text = html_entity_decode(strip_tags($block), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (mb_strlen($text) > 400) {
            $text = mb_substr($text, 0, 399) . '…';
        }

        return [
            'tag' => $tag,
            'classes' => $classes,
            'links' => $links,
            'images' => $images,
            'text' => $text,
        ];
    }

    private static function regionHtml(string $html, string $kind): ?string
    {
        if ($kind === 'header') {
            if (preg_match('/<header\b[^>]*>.*?<\/header>/is', $html, $m) === 1) {
                return $m[0];
            }
            if (preg_match('/<[^>]+role\s*=\s*["\']banner["\'][^>]*>.*?<\/[a-zA-Z0-9]+>/is', $html, $m) === 1) {
                return $m[0];
            }
            if (preg_match('/<([a-zA-Z0-9]+)[^>]*class\s*=\s*["\'][^"\']*(?:site-header|navbar|masthead|page-header)[^"\']*["\'][^>]*>.*?<\/\1>/is', $html, $m) === 1) {
                return $m[0];
            }
        }
        if ($kind === 'footer') {
            if (preg_match('/<footer\b[^>]*>.*?<\/footer>/is', $html, $m) === 1) {
                return $m[0];
            }
            if (preg_match('/<[^>]+role\s*=\s*["\']contentinfo["\'][^>]*>.*?<\/[a-zA-Z0-9]+>/is', $html, $m) === 1) {
                return $m[0];
            }
            if (preg_match('/<([a-zA-Z0-9]+)[^>]*class\s*=\s*["\'][^"\']*(?:site-footer|page-footer)[^"\']*["\'][^>]*>.*?<\/\1>/is', $html, $m) === 1) {
                return $m[0];
            }
        }

        return null;
    }

    private static function normalizeHex(string $raw): ?string
    {
        $hex = ltrim($raw, '#');
        $len = strlen($hex);
        if ($len === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        } elseif ($len === 4) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        } elseif ($len === 8) {
            $hex = substr($hex, 0, 6);
        } elseif ($len !== 6) {
            return null;
        }

        return '#' . strtolower($hex);
    }

    private static function rgbToHex(int $r, int $g, int $b): ?string
    {
        if ($r > 255 || $g > 255 || $b > 255) {
            return null;
        }

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
