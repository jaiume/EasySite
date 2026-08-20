<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\SecretRedactor;
use PHPUnit\Framework\TestCase;

final class SecretRedactorTest extends TestCase
{
    public function testRedactsIniSecretsAndTokens(): void
    {
        $raw = "[auth]\npassword = \"s3cret\"\n[openrouter]\napi_key = \"sk-or-v1-test-fixture\"\n";
        $out = SecretRedactor::text($raw);
        self::assertStringContainsString('password = "[REDACTED]"', $out);
        self::assertStringContainsString('api_key = "[REDACTED]"', $out);
        self::assertStringNotContainsString('s3cret', $out);
        self::assertStringNotContainsString('sk-or-v1-test-fixture', $out);
    }

    public function testRedactsBearerAndGenericSkKeysInLogs(): void
    {
        $raw = "Authorization: Bearer abcdefghijklmnop.xyz\nkey sk-abcdefghijklmnopqrstuvwxyz1234\n";
        $out = SecretRedactor::text($raw);
        self::assertStringContainsString('Bearer [REDACTED]', $out);
        self::assertStringContainsString('sk-[REDACTED]', $out);
        self::assertStringNotContainsString('abcdefghijklmnop.xyz', $out);
    }
}
