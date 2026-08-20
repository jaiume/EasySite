<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\InboxGuard;
use App\Support\PathGuardException;
use PHPUnit\Framework\TestCase;

final class InboxGuardTest extends TestCase
{
    private string $root;
    private InboxGuard $guard;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cp-inbox-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/inbox', 0777, true);
        mkdir($this->root . '/secret', 0777, true);
        file_put_contents($this->root . '/inbox/note.txt', 'hello');
        file_put_contents($this->root . '/secret/key.ini', 'nope');
        $this->guard = new InboxGuard($this->root . '/inbox');
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testReadsInboxFile(): void
    {
        $real = $this->guard->resolveExisting('note.txt');
        self::assertSame(realpath($this->root . '/inbox/note.txt'), $real);
    }

    public function testRejectsParentEscape(): void
    {
        $this->expectException(PathGuardException::class);
        $this->guard->resolveExisting('../secret/key.ini');
    }

    public function testSymlinkEscapeRejected(): void
    {
        symlink($this->root . '/secret/key.ini', $this->root . '/inbox/escape.ini');
        $this->expectException(PathGuardException::class);
        $this->guard->resolveExisting('escape.ini');
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
