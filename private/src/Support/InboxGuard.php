<?php

declare(strict_types=1);

namespace App\Support;

final class InboxGuard
{
    public function __construct(
        private readonly string $root,
    ) {
        if (!is_dir($this->root) && !mkdir($this->root, 0770, true) && !is_dir($this->root)) {
            throw new PathGuardException('Unable to create inbox.');
        }
    }

    public function root(): string
    {
        $real = realpath($this->root);
        if ($real === false) {
            throw new PathGuardException('Missing inbox.');
        }

        return rtrim(str_replace('\\', '/', $real), '/');
    }

    public function resolveExisting(string $userPath): string
    {
        $candidate = $this->join($userPath);
        $real = realpath($candidate);
        if ($real === false) {
            throw new PathGuardException('Inbox path does not exist.');
        }
        $this->assertUnderRoot($real);

        return $real;
    }

    public function resolveForWrite(string $userPath): string
    {
        $candidate = $this->join($userPath);
        $dir = dirname($candidate);
        $base = basename($candidate);
        if ($base === '' || $base === '.' || $base === '..') {
            throw new PathGuardException('Invalid filename.');
        }
        $realDir = realpath($dir);
        if ($realDir === false) {
            throw new PathGuardException('Inbox directory does not exist.');
        }
        $this->assertUnderRoot($realDir);
        $target = $realDir . '/' . $base;
        if (file_exists($target) || is_link($target)) {
            $real = realpath($target);
            if ($real === false) {
                throw new PathGuardException('Broken symlink.');
            }
            $this->assertUnderRoot($real);
        }

        return $target;
    }

    public function assertUnderRoot(string $realPath): void
    {
        $root = $this->root();
        $realPath = rtrim(str_replace('\\', '/', $realPath), '/');
        if ($realPath !== $root && !str_starts_with($realPath, $root . '/')) {
            throw new PathGuardException('Path is outside the inbox.');
        }
    }

    private function join(string $userPath): string
    {
        if (str_contains($userPath, "\0") || preg_match('/[[:cntrl:]]/', $userPath)) {
            throw new PathGuardException('Invalid path.');
        }
        $userPath = str_replace('\\', '/', $userPath);
        $userPath = ltrim($userPath, '/');
        if ($userPath === '') {
            return $this->root();
        }
        foreach (explode('/', $userPath) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new PathGuardException('Parent-directory segments are not allowed.');
            }
        }

        return $this->root() . '/' . $userPath;
    }
}
