<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cp-cfg-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    }

    public function testBaseUrlUsesHttpsHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'cp.dev.tashincconsulting.com';
        $_SERVER['HTTPS'] = 'on';
        $config = $this->configFrom('[auth]
username = "admin"
');
        self::assertSame('https://cp.dev.tashincconsulting.com', $config->baseUrl());
    }

    public function testWriteStringUpdatesExistingKeyAndPreservesComments(): void
    {
        $config = $this->configFrom("; keep me
[openrouter]
; comment
api_key = \"old\"
monthly_spend_cap = 10.00
[auth]
username = \"admin\"
");
        $config->writeString('openrouter', 'api_key', 'sk-new');
        $raw = (string) file_get_contents($this->root . '/config/config.ini');
        self::assertStringContainsString('; keep me', $raw);
        self::assertStringContainsString('; comment', $raw);
        self::assertStringContainsString('api_key = "sk-new"', $raw);
        self::assertStringContainsString('monthly_spend_cap = 10.00', $raw);
        self::assertSame('sk-new', $config->string('openrouter.api_key'));
    }

    private function configFrom(string $ini): Config
    {
        $path = $this->root . '/config/config.ini';
        file_put_contents($path, $ini);

        return new Config($path, $this->root);
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
