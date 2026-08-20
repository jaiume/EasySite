<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\PathGuard;
use App\Support\PathGuardException;
use PHPUnit\Framework\TestCase;

final class PathGuardTest extends TestCase
{
    private string $root;
    private PathGuard $guard;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cp-pg-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public_html/staging', 0777, true);
        mkdir($this->root . '/public_html/cp', 0777, true);
        mkdir($this->root . '/private', 0777, true);
        file_put_contents($this->root . '/public_html/index.php', 'live');
        file_put_contents($this->root . '/public_html/cp/secret.php', 'nope');
        file_put_contents($this->root . '/public_html/staging/index.php', 'draft');
        file_put_contents($this->root . '/private/config.ini', 'secret');
        $this->guard = new PathGuard(
            $this->root . '/public_html/staging',
            $this->root . '/public_html',
            $this->root . '/private',
        );
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testReadsStagingFile(): void
    {
        $real = $this->guard->resolveExisting('index.php');
        self::assertSame(realpath($this->root . '/public_html/staging/index.php'), $real);
    }

    public function testRejectsParentEscape(): void
    {
        $this->expectException(PathGuardException::class);
        $this->guard->resolveExisting('../index.php');
    }

    public function testRejectsControlApp(): void
    {
        $this->expectException(PathGuardException::class);
        $this->guard->resolveExisting('../cp/secret.php');
    }

    public function testRejectsReservedNames(): void
    {
        $this->expectException(PathGuardException::class);
        $this->guard->resolveForWrite('cp/hack.php');
    }

    public function testWriteStaysInStaging(): void
    {
        $target = $this->guard->resolveForWrite('about.php');
        self::assertStringStartsWith(realpath($this->root . '/public_html/staging') . '/', $target);
    }

    public function testSymlinkEscapeRejected(): void
    {
        $link = $this->root . '/public_html/staging/escape.php';
        symlink($this->root . '/public_html/index.php', $link);
        $this->expectException(PathGuardException::class);
        $this->guard->resolveExisting('escape.php');
    }

    public function testDetectsPngMagic(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 16);
        self::assertTrue($this->guard->detectBinaryMagic($png));
        self::assertFalse($this->guard->detectBinaryMagic('<html></html>'));
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
            $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
