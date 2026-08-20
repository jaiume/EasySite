<?php

declare(strict_types=1);

namespace App\Support;

final class PathGuard
{
    private const BINARY_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'bmp', 'pdf', 'zip', 'gz', 'woff', 'woff2', 'ttf', 'eot'];

    public function __construct(
        private readonly string $stagingRoot,
        private readonly string $docroot,
        private readonly string $appPrivateDir,
    ) {
    }

    public function stagingRoot(): string
    {
        return $this->mustReal($this->stagingRoot, 'staging root');
    }

    public function assertUnderStaging(string $realPath): void
    {
        $root = $this->stagingRoot();
        $realPath = $this->normalize($realPath);
        if ($realPath !== $root && !str_starts_with($realPath, $root . '/')) {
            throw new PathGuardException('Path is outside the staging tree.');
        }
        $this->assertNotReserved($realPath, $root);
        $this->assertNotSensitive($realPath);
    }

    public function resolveExisting(string $userPath): string
    {
        $candidate = $this->joinStaging($userPath);
        $real = realpath($candidate);
        if ($real === false) {
            throw new PathGuardException('Path does not exist.');
        }
        $this->assertUnderStaging($real);

        return $real;
    }

    /**
     * Resolve a path for create/write. Parent directory must exist.
     */
    public function resolveForWrite(string $userPath): string
    {
        $candidate = $this->joinStaging($userPath);
        $dir = dirname($candidate);
        $base = basename($candidate);
        $this->assertSafeBasename($base);
        $realDir = realpath($dir);
        if ($realDir === false) {
            throw new PathGuardException('Parent directory does not exist.');
        }
        $this->assertUnderStaging($realDir);
        $target = $realDir . '/' . $base;
        if (is_link($target)) {
            $linkReal = realpath($target);
            if ($linkReal === false) {
                throw new PathGuardException('Broken symlink.');
            }
            $this->assertUnderStaging($linkReal);
        } elseif (file_exists($target)) {
            $this->assertUnderStaging((string) realpath($target));
        }

        $this->assertNotReserved($target, $this->stagingRoot());

        return $target;
    }

    public function isBinaryExtension(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, self::BINARY_EXTENSIONS, true);
    }

    public function detectBinaryMagic(string $bytes): bool
    {
        if ($bytes === '') {
            return false;
        }
        $info = @getimagesizefromstring($bytes);
        if (is_array($info)) {
            return true;
        }
        $prefix = substr($bytes, 0, 8);
        if (str_starts_with($prefix, "\x89PNG") || str_starts_with($prefix, "GIF87a") || str_starts_with($prefix, "GIF89a")) {
            return true;
        }
        if (str_starts_with($prefix, "\xFF\xD8\xFF")) {
            return true;
        }
        if (str_starts_with($prefix, 'RIFF') && str_contains(substr($bytes, 0, 16), 'WEBP')) {
            return true;
        }

        return false;
    }

    public function sanitizeImageBasename(string $name): string
    {
        $name = basename(str_replace("\0", '', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'image';
        $name = ltrim($name, '.');
        if ($name === '') {
            $name = 'image';
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $name .= '.png';
        }

        return $name;
    }

    private function joinStaging(string $userPath): string
    {
        if (str_contains($userPath, "\0") || preg_match('/[[:cntrl:]]/', $userPath)) {
            throw new PathGuardException('Invalid path.');
        }
        $userPath = str_replace('\\', '/', $userPath);
        $userPath = ltrim($userPath, '/');
        if ($userPath === '') {
            return $this->stagingRoot();
        }
        $parts = explode('/', $userPath);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new PathGuardException('Parent-directory segments are not allowed.');
            }
            if ($part === 'cp' || $part === 'staging') {
                throw new PathGuardException('Reserved path name.');
            }
        }

        return $this->stagingRoot() . '/' . $userPath;
    }

    private function assertSafeBasename(string $base): void
    {
        if ($base === '' || $base === '.' || $base === '..' || str_contains($base, '/') || str_contains($base, "\0")) {
            throw new PathGuardException('Invalid filename.');
        }
        if ($base === 'cp' || $base === 'staging') {
            throw new PathGuardException('Reserved path name.');
        }
    }

    private function assertNotReserved(string $realPath, string $stagingRoot): void
    {
        $rel = substr($realPath, strlen($stagingRoot));
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '') {
            return;
        }
        foreach (explode('/', $rel) as $part) {
            if ($part === 'cp' || $part === 'staging') {
                throw new PathGuardException('Reserved path name.');
            }
        }
    }

    private function assertNotSensitive(string $realPath): void
    {
        $doc = $this->mustReal($this->docroot, 'docroot');
        $cp = $doc . '/cp';
        $private = $this->mustReal($this->appPrivateDir, 'private');
        if ($realPath === $cp || str_starts_with($realPath, $cp . '/')) {
            throw new PathGuardException('Cannot access the control app.');
        }
        if ($realPath === $private || str_starts_with($realPath, $private . '/')) {
            throw new PathGuardException('Cannot access application private files.');
        }
        $staging = $this->stagingRoot();
        if ($realPath === $doc || (str_starts_with($realPath, $doc . '/') && !str_starts_with($realPath, $staging . '/') && $realPath !== $staging)) {
            if (!str_starts_with($realPath, $staging . '/') && $realPath !== $staging) {
                throw new PathGuardException('Cannot access live files.');
            }
        }
    }

    private function mustReal(string $path, string $label): string
    {
        $real = realpath($path);
        if ($real === false) {
            throw new PathGuardException('Missing ' . $label . '.');
        }

        return $this->normalize($real);
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
