<?php

declare(strict_types=1);

namespace App\Support;

final class MessageCompactor
{
    /**
     * Drop bulky old tool payloads so later model rounds stay small enough to finish.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public static function compact(array $messages, int $keepRecentTools = 8, int $maxChars = 4000): array
    {
        if ($keepRecentTools < 1) {
            $keepRecentTools = 1;
        }
        $toolIndexes = [];
        foreach ($messages as $i => $row) {
            if (is_array($row) && ($row['role'] ?? '') === 'tool') {
                $toolIndexes[] = $i;
            }
        }
        $keep = array_flip(array_slice($toolIndexes, -$keepRecentTools));
        $stub = '{"omitted":true,"note":"Older tool output was dropped to save space. Do not call that tool again. Use what you already learned and write_file."}';
        foreach ($messages as $i => $row) {
            if (!is_array($row) || ($row['role'] ?? '') !== 'tool') {
                continue;
            }
            $content = $row['content'] ?? '';
            if (!isset($keep[$i])) {
                $messages[$i]['content'] = $stub;
                continue;
            }
            if (is_array($content)) {
                $messages[$i]['content'] = self::compactParts($content, $maxChars);
                continue;
            }
            if (!is_string($content)) {
                continue;
            }
            if (strlen($content) > $maxChars) {
                $messages[$i]['content'] = mb_substr($content, 0, $maxChars) . '…';
            }
        }

        return $messages;
    }

    /**
     * @param list<mixed> $parts
     * @return string|list<array<string, mixed>>
     */
    private static function compactParts(array $parts, int $maxChars): mixed
    {
        $text = '';
        $hasImage = false;
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (($part['type'] ?? '') === 'text') {
                $text .= (string) ($part['text'] ?? '');
            }
            if (($part['type'] ?? '') === 'image_url') {
                $hasImage = true;
            }
        }
        if (strlen($text) > $maxChars) {
            return mb_substr($text, 0, $maxChars) . '…';
        }
        if ($hasImage && strlen($text) <= $maxChars) {
            return $parts;
        }

        return $text !== '' ? $text : $parts;
    }
}
