<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\InboxGuard;
use App\Support\PathGuard;
use App\Support\PathGuardException;
use App\Support\ServiceResult;

final class AttachmentService
{
    private const DOC_EXTENSIONS = ['txt', 'md', 'csv', 'html', 'htm', 'json', 'xml', 'pdf', 'doc', 'docx', 'rtf', 'odt'];
    private const TEXT_EXTENSIONS = ['txt', 'md', 'csv', 'html', 'htm', 'json', 'xml'];
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private const EXCERPT_CHARS = 50000;

    public function __construct(
        private readonly InboxGuard $inbox,
        private readonly PathGuard $pathGuard,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function save(string $bytes, string $suggestedName, string $clientMime = ''): array
    {
        if ($bytes === '') {
            return ServiceResult::fail('File is empty.', 'VALIDATION_ERROR');
        }
        $max = $this->config->int('security.image_max_bytes', 5242880);
        if (strlen($bytes) > $max) {
            return ServiceResult::fail('File is too large.', 'TOO_LARGE');
        }
        try {
            if ($this->pathGuard->detectBinaryMagic($bytes)) {
                return ServiceResult::ok('Saved to inbox', $this->saveImage($bytes, $suggestedName));
            }
            $ext = strtolower(pathinfo($suggestedName, PATHINFO_EXTENSION));
            if (!in_array($ext, self::DOC_EXTENSIONS, true)) {
                return ServiceResult::fail(
                    'That file type is not allowed. Drop an image or a document (txt, md, csv, html, json, xml, pdf, doc, docx, rtf, odt).',
                    'TYPE_DENIED'
                );
            }

            return ServiceResult::ok('Saved to inbox', $this->writeInboxFile($bytes, $suggestedName, $ext, $clientMime, 'document'));
        } catch (PathGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'PATH_DENIED');
        }
    }

    /**
     * @param list<string> $paths
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function promptFromPaths(array $paths): array
    {
        $blocks = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $path = ltrim(str_replace('\\', '/', $path), '/');
            try {
                $real = $this->inbox->resolveExisting($path);
            } catch (PathGuardException $e) {
                return ServiceResult::fail($e->getMessage(), 'PATH_DENIED');
            }
            if (!is_file($real)) {
                return ServiceResult::fail('Attachment is missing.', 'NOT_A_FILE');
            }
            $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
            $size = filesize($real);
            $kind = $this->isImagePath($real) ? 'image' : 'document';
            $line = '- inbox `' . $path . '` (' . $kind . ', ' . ($size === false ? 0 : $size) . ' bytes)';
            if (in_array($ext, self::TEXT_EXTENSIONS, true)) {
                $text = (string) file_get_contents($real);
                if (!str_contains($text, "\0")) {
                    if (mb_strlen($text) > self::EXCERPT_CHARS) {
                        $text = mb_substr($text, 0, self::EXCERPT_CHARS) . "\n…";
                    }
                    $line .= "\n```\n" . $text . "\n```";
                }
            }
            $blocks[] = $line;
        }
        if ($blocks === []) {
            return ServiceResult::ok('No attachments', ['prompt' => '']);
        }
        $prompt = "The owner attached these files to the chat. They are in the private inbox, NOT on the draft site. "
            . "Use them as context. If the owner wants a file on the site, copy it with import_to_staging "
            . "(images typically to images/<filename>).\n"
            . implode("\n", $blocks);

        return ServiceResult::ok('Attachment prompt', ['prompt' => $prompt]);
    }

    /**
     * @return array{kind: string, filename: string, path: string, mime: string, bytes: int, width?: int|null, height?: int|null}
     */
    private function saveImage(string $bytes, string $suggestedName): array
    {
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) && isset($info['mime']) ? (string) $info['mime'] : '';
        if (!in_array($mime, self::IMAGE_MIMES, true)) {
            throw new PathGuardException('Image type is not allowed.');
        }
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'png',
        };
        $saved = $this->writeInboxFile($bytes, $suggestedName, $ext, $mime, 'image');
        $saved['width'] = is_array($info) ? ($info[0] ?? null) : null;
        $saved['height'] = is_array($info) ? ($info[1] ?? null) : null;

        return $saved;
    }

    /**
     * @return array{kind: string, filename: string, path: string, mime: string, bytes: int}
     */
    private function writeInboxFile(string $bytes, string $suggestedName, string $ext, string $mime, string $kind): array
    {
        $name = $this->sanitizeBasename($suggestedName, $ext);
        $name = $this->uniqueName($name);
        $target = $this->inbox->resolveForWrite($name);
        $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $bytes) === false) {
            throw new PathGuardException('Unable to write inbox file.');
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new PathGuardException('Unable to finalize inbox file.');
        }

        return [
            'kind' => $kind,
            'filename' => $name,
            'path' => $name,
            'mime' => $mime !== '' ? $mime : (mime_content_type($target) ?: 'application/octet-stream'),
            'bytes' => strlen($bytes),
        ];
    }

    private function sanitizeBasename(string $name, string $ext): string
    {
        $name = basename(str_replace("\0", '', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'file';
        $name = ltrim($name, '.');
        $base = pathinfo($name, PATHINFO_FILENAME);
        if ($base === '') {
            $base = 'file';
        }

        return $base . '.' . $ext;
    }

    private function uniqueName(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $try = $name;
        $i = 1;
        while (true) {
            $path = $this->inbox->resolveForWrite($try);
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

    private function isImagePath(string $real): bool
    {
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }
}
