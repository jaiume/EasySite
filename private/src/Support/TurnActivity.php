<?php

declare(strict_types=1);

namespace App\Support;

final class TurnActivity
{
    public const MAX_ITEMS = 200;

    /**
     * @param array<string, mixed> $args
     */
    public static function toolLine(string $name, array $args): string
    {
        unset($args['content'], $args['new']);
        $parts = [];
        foreach (['path', 'url', 'from', 'to', 'query', 'filename', 'old'] as $key) {
            if (isset($args[$key]) && is_string($args[$key]) && $args[$key] !== '') {
                $parts[] = $key === 'old' ? self::clip($args[$key], 40) : $args[$key];
            }
        }
        if (isset($args['width']) && is_numeric($args['width'])) {
            $parts[] = ((int) $args['width']) . 'px';
        }
        if (isset($args['prompt']) && is_string($args['prompt']) && $args['prompt'] !== '') {
            $parts[] = self::clip($args['prompt'], 80);
        }
        $detail = $parts === [] ? '' : ' — ' . implode(' → ', $parts);

        return 'Using ' . ($name !== '' ? $name : 'tool') . $detail;
    }

    public static function isSliceYieldNote(string $text): bool
    {
        return self::isQuietNote($text);
    }

    public static function isQuietNote(string $text): bool
    {
        if (
            $text === 'Paused. Press Continue to keep going.'
            || $text === 'Waiting for the model…'
            || $text === 'Waiting for the model...'
            || $text === 'Stopped.'
        ) {
            return true;
        }

        return str_starts_with($text, 'Paused:');
    }

    public static function clip(string $text, int $max): string
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

    /**
     * Rebuild a compact log from OpenRouter messages (completed tools only).
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array{kind: string, text: string, name?: string}>
     */
    public static function fromModelMessages(array $messages): array
    {
        $answered = [];
        foreach ($messages as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['role'] ?? '') === 'tool' && isset($row['tool_call_id']) && is_string($row['tool_call_id'])) {
                $answered[$row['tool_call_id']] = true;
            }
        }
        $out = [];
        foreach ($messages as $row) {
            if (!is_array($row) || ($row['role'] ?? '') !== 'assistant') {
                continue;
            }
            $calls = $row['tool_calls'] ?? null;
            if (!is_array($calls) || $calls === []) {
                continue;
            }
            $content = $row['content'] ?? '';
            if (is_string($content)) {
                $content = trim($content);
                if ($content !== '') {
                    $out[] = [
                        'kind' => 'status',
                        'text' => self::clip($content, 200),
                    ];
                }
            }
            foreach ($calls as $call) {
                if (!is_array($call)) {
                    continue;
                }
                $id = (string) ($call['id'] ?? '');
                if ($id === '' || !isset($answered[$id])) {
                    continue;
                }
                $name = (string) ($call['function']['name'] ?? 'tool');
                $raw = $call['function']['arguments'] ?? '{}';
                $args = is_string($raw) ? json_decode($raw, true) : $raw;
                if (!is_array($args)) {
                    $args = [];
                }
                $item = [
                    'kind' => 'tool',
                    'text' => self::toolLine($name, $args),
                    'name' => $name,
                ];
                $out[] = $item;
            }
        }

        return self::normalize($out);
    }

    /**
     * @return list<array{kind: string, text: string, name?: string}>
     */
    public static function normalize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $text = isset($row['text']) && is_string($row['text']) ? trim($row['text']) : '';
            if ($text === '' || self::isQuietNote($text)) {
                continue;
            }
            $kind = (string) ($row['kind'] ?? 'status');
            if ($kind !== 'tool' && str_starts_with($text, 'Using ')) {
                $kind = 'tool';
            }
            if ($kind !== 'tool') {
                $kind = 'status';
            }
            $item = [
                'kind' => $kind,
                'text' => $text,
            ];
            if (isset($row['name']) && is_string($row['name']) && $row['name'] !== '') {
                $item['name'] = $row['name'];
            }
            $out[] = $item;
        }
        if (count($out) > self::MAX_ITEMS) {
            $out = array_slice($out, -self::MAX_ITEMS);
        }

        return array_values($out);
    }
}
