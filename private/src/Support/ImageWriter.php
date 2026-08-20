<?php

declare(strict_types=1);

namespace App\Support;

final class ImageWriter
{
    public function __construct(
        private readonly PathGuard $pathGuard,
        private readonly int $maxBytes,
    ) {
    }

    /**
     * @return array{filename: string, path: string, mime: string, bytes: int, width: int|null, height: int|null}
     */
    public function save(string $bytes, string $suggestedName): array
    {
        if ($bytes === '' || strlen($bytes) > $this->maxBytes) {
            throw new PathGuardException('Image is empty or too large.');
        }
        if (!$this->pathGuard->detectBinaryMagic($bytes)) {
            throw new PathGuardException('File is not a recognized image.');
        }
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) && isset($info['mime']) ? (string) $info['mime'] : '';
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            throw new PathGuardException('Image type is not allowed.');
        }
        $imagesDir = $this->pathGuard->resolveForWrite('images');
        if (!is_dir($imagesDir) && !mkdir($imagesDir, 0775, true) && !is_dir($imagesDir)) {
            throw new PathGuardException('Unable to create images directory.');
        }
        $name = $this->pathGuard->sanitizeImageBasename($suggestedName);
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'png',
        };
        $name = pathinfo($name, PATHINFO_FILENAME) . '.' . $ext;
        $name = $this->uniqueName($name);
        $target = $this->pathGuard->resolveForWrite('images/' . $name);
        $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $bytes) === false) {
            throw new PathGuardException('Unable to write image.');
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new PathGuardException('Unable to finalize image.');
        }

        return [
            'filename' => $name,
            'path' => 'images/' . $name,
            'mime' => $mime,
            'bytes' => strlen($bytes),
            'width' => is_array($info) ? ($info[0] ?? null) : null,
            'height' => is_array($info) ? ($info[1] ?? null) : null,
        ];
    }

    private function uniqueName(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $try = $name;
        $i = 1;
        while (true) {
            try {
                $path = $this->pathGuard->resolveForWrite('images/' . $try);
            } catch (PathGuardException) {
                return $try;
            }
            if (!file_exists($path)) {
                return $try;
            }
            $try = $base . '-' . $i . '.' . $ext;
            $i++;
            if ($i > 200) {
                return $base . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
            }
        }
    }
}
