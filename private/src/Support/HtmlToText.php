<?php

declare(strict_types=1);

namespace App\Support;

final class HtmlToText
{
    public function extract(string $html, int $maxChars = 20000): array
    {
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $stripped = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $stripped = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $stripped) ?? $stripped;
        $stripped = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $stripped) ?? $stripped;
        $text = html_entity_decode(strip_tags($stripped), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);
        if (mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars) . '…';
        }

        return [
            'title' => $title,
            'text' => $text,
        ];
    }

    /**
     * @return list<string>
     */
    public function hrefs(string $html, string $baseUrl): array
    {
        $urls = [];
        if (preg_match_all('/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:')) {
                    continue;
                }
                $resolved = $this->resolveUrl($baseUrl, $href);
                if ($resolved !== null) {
                    $urls[] = $resolved;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return list<string>
     */
    public function stylesheetUrls(string $html, string $baseUrl): array
    {
        $urls = [];
        if (preg_match_all('/<link\b[^>]*>/i', $html, $tags) > 0) {
            foreach ($tags[0] as $tag) {
                if (!preg_match('/rel\s*=\s*["\'][^"\']*stylesheet[^"\']*["\']/i', $tag)) {
                    continue;
                }
                if (!preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $tag, $m)) {
                    continue;
                }
                $href = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $resolved = $this->resolveUrl($baseUrl, $href);
                if ($resolved !== null) {
                    $urls[] = $resolved;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    public function resolveUrl(string $base, string $rel): ?string
    {
        $rel = trim($rel);
        if (str_starts_with($rel, '//')) {
            $rel = 'https:' . $rel;
        }
        if (preg_match('#^https://#i', $rel)) {
            return $this->normalizeUrl($rel);
        }
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || empty($baseParts['host']) || empty($baseParts['scheme'])) {
            return null;
        }
        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $origin = $scheme . '://' . $host . $port;
        if (str_starts_with($rel, '/')) {
            return $this->normalizeUrl($origin . $rel);
        }
        $basePath = $baseParts['path'] ?? '/';
        if (!str_ends_with($basePath, '/')) {
            $basePath = dirname($basePath);
            if ($basePath === '\\' || $basePath === '.') {
                $basePath = '/';
            }
            if (!str_ends_with($basePath, '/')) {
                $basePath .= '/';
            }
        }

        return $this->normalizeUrl($origin . $basePath . $rel);
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }
        $path = $parts['path'] ?? '/';
        $path = preg_replace('#/\./#', '/', $path) ?? $path;
        $segments = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $seg;
        }
        $path = '/' . implode('/', $segments);
        if (str_ends_with($url, '/') && !str_ends_with($path, '/')) {
            $path .= '/';
        }
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return 'https://' . strtolower($parts['host']) . $path . $query;
    }
}
