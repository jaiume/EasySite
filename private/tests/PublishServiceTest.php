<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\PublishService;
use App\Support\Config;
use PHPUnit\Framework\TestCase;

final class PublishServiceTest extends TestCase
{
    private string $root;
    private PublishService $publish;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cp-pub-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public_html/staging', 0777, true);
        mkdir($this->root . '/public_html/cp', 0777, true);
        mkdir($this->root . '/private/config', 0777, true);
        mkdir($this->root . '/private/var/data', 0777, true);
        file_put_contents($this->root . '/public_html/index.php', 'OLD LIVE');
        file_put_contents($this->root . '/public_html/cp/keep.php', 'CONTROL');
        file_put_contents($this->root . '/public_html/staging/index.php', 'NEW DRAFT');
        file_put_contents($this->root . '/public_html/staging/about.php', 'ABOUT');
        $ini = <<<INI
[app]
name = test
[paths]
docroot = "{$this->root}/public_html"
staging_root = "{$this->root}/public_html/staging"
live_root = "{$this->root}/public_html"
[security]
backup_keep = 10
INI;
        $iniPath = $this->root . '/private/config/config.ini';
        file_put_contents($iniPath, $ini);
        $config = new Config($iniPath, $this->root . '/private');
        $this->publish = new PublishService($config);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testReservedNames(): void
    {
        self::assertTrue($this->publish->isReservedName('cp'));
        self::assertTrue($this->publish->isReservedName('staging'));
        self::assertFalse($this->publish->isReservedName('index.php'));
    }

    public function testPublishLeavesControlAppAndUpdatesLive(): void
    {
        $result = $this->publish->publish();
        self::assertTrue($result['success'], $result['message']);
        self::assertSame('NEW DRAFT', (string) file_get_contents($this->root . '/public_html/index.php'));
        self::assertSame('ABOUT', (string) file_get_contents($this->root . '/public_html/about.php'));
        self::assertSame('CONTROL', (string) file_get_contents($this->root . '/public_html/cp/keep.php'));
        self::assertDirectoryExists($this->root . '/public_html/staging');
        self::assertSame('NEW DRAFT', (string) file_get_contents($this->root . '/public_html/staging/index.php'));
    }

    public function testRollbackRestoresPreviousLive(): void
    {
        $this->publish->publish();
        file_put_contents($this->root . '/public_html/staging/index.php', 'EVEN NEWER');
        $this->publish->publish();
        $result = $this->publish->rollback();
        self::assertTrue($result['success'], $result['message']);
        self::assertSame('NEW DRAFT', (string) file_get_contents($this->root . '/public_html/index.php'));
        self::assertSame('CONTROL', (string) file_get_contents($this->root . '/public_html/cp/keep.php'));
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
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
