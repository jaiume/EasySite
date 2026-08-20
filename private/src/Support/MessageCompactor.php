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
            $content = (string) ($row['content'] ?? '');
            if (!isset($keep[$i])) {
                $messages[$i]['content'] = $stub;
                continue;
            }
            if (strlen($content) > $maxChars) {
                $messages[$i]['content'] = mb_substr($content, 0, $maxChars) . '…';
            }
        }

        return $messages;
    }
}
