<?php

declare(strict_types=1);

namespace App\Support;

final class SecretRedactor
{
    public static function text(string $text): string
    {
        $text = preg_replace('/sk-or-v1-[A-Za-z0-9_\-]+/', 'sk-or-v1-[REDACTED]', $text) ?? $text;
        $text = preg_replace('/\bsk-[A-Za-z0-9]{20,}/', 'sk-[REDACTED]', $text) ?? $text;
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [REDACTED]', $text) ?? $text;
        $text = preg_replace(
            '/^(\s*(?:api_key|password)\s*=\s*")(?:[^"]*)(")/im',
            '$1[REDACTED]$2',
            $text
        ) ?? $text;

        return $text;
    }
}
