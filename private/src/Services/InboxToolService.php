<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\InboxGuard;
use App\Support\PathGuard;
use App\Support\PathGuardException;
use App\Support\ServiceResult;

final class InboxToolService
{
    private const FORBIDDEN_DEST_EXT = ['php', 'phtml', 'phar', 'cgi', 'htaccess', 'exe', 'sh'];

    public function __construct(
        private readonly InboxGuard $inbox,
        private readonly PathGuard $pathGuard,
        private readonly ToolLogger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function listInbox(array $args): array
    {
        try {
            $root = $this->inbox->root();
            $items = @scandir($root);
            if (!is_array($items)) {
                return ServiceResult::fail('Unable to list inbox.', 'LIST_FAILED');
            }
            $entries = [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $full = $root . '/' . $item;
                $entries[] = [
                    'path' => $item,
                    'type' => is_dir($full) ? 'dir' : 'file',
                    'bytes' => is_file($full) ? (filesize($full) ?: 0) : 0,
                ];
            }
            $this->logger->log('list_inbox', '.', count($entries));

            return ServiceResult::ok('Inbox listing', ['entries' => $entries]);
        } catch (PathGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'PATH_DENIED');
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function readInbox(array $args): array
    {
        try {
            $path = (string) ($args['path'] ?? '');
            $real = $this->inbox->resolveExisting($path);
            if (!is_file($real)) {
                return ServiceResult::fail('Not a file.', 'NOT_A_FILE');
            }
            $size = filesize($real);
            $size = $size === false ? 0 : $size;
            $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
            $contents = (string) file_get_contents($real);
            if ($this->pathGuard->isBinaryExtension($real) || $this->pathGuard->detectBinaryMagic($contents) || str_contains($contents, "\0")) {
                $this->logger->log('read_inbox', $path, $size);

                return ServiceResult::ok('Binary inbox file (contents omitted)', [
                    'path' => $path,
                    'bytes' => $size,
                    'mime' => mime_content_type($real) ?: 'application/octet-stream',
                    'binary' => true,
                ]);
            }
            $this->logger->log('read_inbox', $path, strlen($contents));

            return ServiceResult::ok('Inbox file contents', [
                'path' => $path,
                'bytes' => strlen($contents),
                'content' => $contents,
                'binary' => false,
            ]);
        } catch (PathGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'PATH_DENIED');
        }
    }

    /**
     * Copy an inbox file onto the staging draft. Does not delete the inbox original.
     *
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function importToStaging(array $args): array
    {
        try {
            $from = (string) ($args['from'] ?? $args['path'] ?? '');
            $to = trim((string) ($args['to'] ?? ''));
            $src = $this->inbox->resolveExisting($from);
            if (!is_file($src)) {
                return ServiceResult::fail('Inbox file does not exist.', 'NOT_A_FILE');
            }
            if ($to === '') {
                $name = basename($src);
                $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
                $to = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)
                    ? 'images/' . $name
                    : 'files/' . $name;
            }
            $destExt = strtolower(pathinfo($to, PATHINFO_EXTENSION));
            if (in_array($destExt, self::FORBIDDEN_DEST_EXT, true)) {
                return ServiceResult::fail('That destination file type is not allowed.', 'TYPE_DENIED');
            }
            $dest = $this->pathGuard->resolveForWrite($to);
            $dir = dirname($dest);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                return ServiceResult::fail('Unable to create destination directory.', 'WRITE_FAILED');
            }
            $tmp = $dest . '.tmp.' . bin2hex(random_bytes(4));
            if (!copy($src, $tmp) || !rename($tmp, $dest)) {
                @unlink($tmp);

                return ServiceResult::fail('Copy to staging failed.', 'WRITE_FAILED');
            }
            $bytes = filesize($dest) ?: 0;
            $this->logger->log('import_to_staging', $from . ' -> ' . $to, $bytes);

            return ServiceResult::ok('Copied inbox file into staging', [
                'from' => $from,
                'to' => $to,
                'bytes' => $bytes,
            ]);
        } catch (PathGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'PATH_DENIED');
        }
    }
}
