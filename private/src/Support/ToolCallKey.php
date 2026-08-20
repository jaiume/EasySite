<?php

declare(strict_types=1);

namespace App\Support;

final class ToolCallKey
{
    /**
     * @param array<string, mixed> $args
     */
    public static function fingerprint(string $name, array $args): string
    {
        if ($name === 'write_file') {
            return 'write_file:' . (string) ($args['path'] ?? '') . ':' . hash('sha256', (string) ($args['content'] ?? ''));
        }
        if ($name === 'search') {
            $path = (string) ($args['path'] ?? '.');
            if ($path === '') {
                $path = '.';
            }
            $args['path'] = $path;
        }
        ksort($args);

        return $name . ':' . json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Fingerprints of tool calls that already have a tool result in this turn.
     *
     * @param list<array<string, mixed>> $messages
     * @return array<string, true>
     */
    public static function completed(array $messages): array
    {
        $open = [];
        $done = [];
        foreach ($messages as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = (string) ($row['role'] ?? '');
            if ($role === 'assistant') {
                $calls = $row['tool_calls'] ?? null;
                if (!is_array($calls)) {
                    continue;
                }
                foreach ($calls as $call) {
                    if (!is_array($call)) {
                        continue;
                    }
                    $id = (string) ($call['id'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    $name = (string) ($call['function']['name'] ?? '');
                    $raw = $call['function']['arguments'] ?? '{}';
                    $args = is_string($raw) ? json_decode($raw, true) : $raw;
                    if (!is_array($args)) {
                        $args = [];
                    }
                    $open[$id] = self::fingerprint($name, $args);
                }
                continue;
            }
            if ($role !== 'tool') {
                continue;
            }
            $id = (string) ($row['tool_call_id'] ?? '');
            if ($id !== '' && isset($open[$id])) {
                $done[$open[$id]] = true;
            }
        }

        return $done;
    }

    public static function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = ltrim($path, '/');
        if ($path === '' || $path === '.') {
            return '.';
        }

        return $path;
    }

    /**
     * Completed read_file / search usage in this turn.
     *
     * @param list<array<string, mixed>> $messages
     * @return array{read: array<string, true>, searched: array<string, true>, searches: int}
     */
    public static function fileUsage(array $messages): array
    {
        $open = [];
        $read = [];
        $searched = [];
        $searches = 0;
        foreach ($messages as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = (string) ($row['role'] ?? '');
            if ($role === 'assistant') {
                $calls = $row['tool_calls'] ?? null;
                if (!is_array($calls)) {
                    continue;
                }
                foreach ($calls as $call) {
                    if (!is_array($call)) {
                        continue;
                    }
                    $id = (string) ($call['id'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    $name = (string) ($call['function']['name'] ?? '');
                    $raw = $call['function']['arguments'] ?? '{}';
                    $args = is_string($raw) ? json_decode($raw, true) : $raw;
                    if (!is_array($args)) {
                        $args = [];
                    }
                    $open[$id] = [
                        'name' => $name,
                        'path' => self::normalizePath((string) ($args['path'] ?? '.')),
                    ];
                }
                continue;
            }
            if ($role !== 'tool') {
                continue;
            }
            $id = (string) ($row['tool_call_id'] ?? '');
            if ($id === '' || !isset($open[$id])) {
                continue;
            }
            $name = $open[$id]['name'];
            $path = $open[$id]['path'];
            $decoded = json_decode((string) ($row['content'] ?? ''), true);
            $skipped = is_array($decoded) && is_array($decoded['data'] ?? null) && !empty($decoded['data']['skipped']);
            if ($name === 'read_file') {
                $read[$path] = true;
            }
            if ($name === 'search' && !$skipped) {
                $searched[$path] = true;
                $searches++;
            }
        }

        return [
            'read' => $read,
            'searched' => $searched,
            'searches' => $searches,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @param array{read: array<string, true>, searched: array<string, true>, searches: int} $usage
     */
    public static function searchBlockReason(array $args, array $usage): ?string
    {
        $path = self::normalizePath((string) ($args['path'] ?? '.'));
        if (isset($usage['read'][$path])) {
            return 'You already read ' . $path . '. Do not search it. Call edit_file with an exact snippet from that read.';
        }
        if (isset($usage['searched'][$path])) {
            return 'You already searched ' . $path . '. Use those hits. Call edit_file now.';
        }
        if (($usage['searches'] ?? 0) >= 2) {
            return 'Search limit reached for this turn. Call edit_file or write_file with what you already know.';
        }

        return null;
    }

    public static function fetchBlockReason(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if (
            str_contains($path, '/media/templates/')
            || str_contains($path, '/cassiopeia/')
            || str_contains($path, '/templates/site/')
            || str_contains($path, '/media/vendor/')
        ) {
            return 'Do not download Joomla or theme CSS/JS. Call inspect_page on the page URL for colours and layout, then edit_file on css/site.css.';
        }
        if (str_ends_with($path, '.css') || str_ends_with($path, '.js') || str_ends_with($path, '.min.css') || str_ends_with($path, '.min.js')) {
            return 'Do not fetch CSS or JS files. Call inspect_page (live) or inspect_draft (staging) instead.';
        }
        if (str_contains($host, 'web.archive.org') || str_contains($host, 'archive.org')) {
            return 'Skip archive.org. Call fetch_page on the live page once, or inspect_page for look.';
        }

        return null;
    }
}
