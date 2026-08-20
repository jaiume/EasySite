<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\RevisionStore;
use App\Support\Config;
use App\Support\PathGuard;
use App\Support\PathGuardException;
use App\Support\SearchQuery;
use App\Support\ServiceResult;

final class FileToolService
{
    private const SEARCH_EXTS = ['html', 'htm', 'css', 'php', 'js', 'md', 'txt'];

    public function __construct(
        private readonly PathGuard $pathGuard,
        private readonly Config $config,
        private readonly RevisionStore $revisions,
        private readonly ToolLogger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function listDir(array $args): array
    {
        try {
            $path = (string) ($args['path'] ?? '.');
            $depth = (int) ($args['depth'] ?? 1);
            if ($depth < 1) {
                $depth = 1;
            }
            if ($depth > 4) {
                $depth = 4;
            }
            $real = $this->pathGuard->resolveExisting($path);
            if (!is_dir($real)) {
                return ServiceResult::fail('Not a directory.', 'NOT_A_DIRECTORY');
            }
            $cap = $this->config->int('security.list_dir_max_entries', 200);
            $entries = [];
            $truncated = false;
            $this->walk($real, $this->pathGuard->stagingRoot(), 1, $depth, $cap, $entries, $truncated);
            $this->logger->log('list_dir', $path, count($entries));

            return ServiceResult::ok('Directory listing', [
                'entries' => $entries,
                'truncated' => $truncated,
            ]);
        } catch (PathGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'PATH_DENIED');
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function readFile(array $args): array
    {
        try {
            $path = (string) ($args['path'] ?? '');
            $real = $this->pathGuard->resolveExisting($path);
            if (!is_file($real)) {
                return ServiceResult::fail('Not a file.', 'NOT_A_FILE');
            }
            $size = filesize($real);
            $size = $size === false ? 0 : $size;
            if ($this->pathGuard->isBinaryExtension($real) || $this->looksBinaryFile($real)) {
                $this->logger->log('read_file', $path, $size);

                return ServiceResult::ok('Binary file (contents omitted)', [
                    'path' => $path,
                    'bytes' => $size,
                    'mime' => mime_content_type($real) ?: 'application/octet-stream',
                    'binary' => true,
                ]);
            }
            $cap = $this->config->int('security.read_file_max_bytes', 204800);
            if ($size > $cap) {
                return ServiceResult::fail('File exceeds read size cap.', 'TOO_LARGE', ['max_bytes' => (string) $cap]);
            }
            $contents = (string) file_get_contents($real);
            if ($this->pathGuard->detectBinaryMagic($contents)) {
                return ServiceResult::ok('Binary file (contents omitted)', [
                    'path' => $path,
                    'bytes' => $size,
                    'mime' => 'application/octet-stream',
                    'binary' => true,
                ]);
            }
            $this->logger->log('read_file', $path, strlen($contents));

            return ServiceResult::ok('File contents', [
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
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function writeFile(array $args): array
    {
        try {
            $path = (string) ($args['path'] ?? '');
            $content = (string) ($args['content'] ?? '');
            if ($this->pathGuard->isBinaryExtension($path) || $this->pathGuard->detectBinaryMagic($content)) {
                return ServiceResult::fail('Images and binaries cannot be written with write_file. Use fetch_image or generate_image.', 'BINARY_FORBIDDEN');
            }
            $target = $this->pathGuard->resolveForWrite($path);

            return $this->persistTextFile($path, $target, $content, 'write_file');
        } catch (PathGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'PATH_DENIED');
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function editFile(array $args): array
    {
        try {
            $path = (string) ($args['path'] ?? '');
            $old = (string) ($args['old'] ?? $args['find'] ?? '');
            $new = (string) ($args['new'] ?? $args['replace'] ?? '');
            $replaceAll = self::truthy($args['replace_all'] ?? false);
            if ($old === '') {
                return ServiceResult::fail('old is required (the exact text to find).', 'VALIDATION_ERROR');
            }
            if ($old === $new) {
                return ServiceResult::fail('old and new are the same.', 'VALIDATION_ERROR');
            }
            if ($this->pathGuard->isBinaryExtension($path) || $this->pathGuard->detectBinaryMagic($new)) {
                return ServiceResult::fail('Images and binaries cannot be written with edit_file. Use fetch_image or generate_image.', 'BINARY_FORBIDDEN');
            }
            $real = $this->pathGuard->resolveExisting($path);
            if (!is_file($real)) {
                return ServiceResult::fail('Not a file.', 'NOT_A_FILE');
            }
            $size = filesize($real);
            $size = $size === false ? 0 : $size;
            $cap = $this->config->int('security.read_file_max_bytes', 204800);
            if ($size > $cap) {
                return ServiceResult::fail('File exceeds edit size cap.', 'TOO_LARGE', ['max_bytes' => (string) $cap]);
            }
            $contents = (string) file_get_contents($real);
            if ($this->pathGuard->detectBinaryMagic($contents)) {
                return ServiceResult::fail('Images and binaries cannot be written with edit_file.', 'BINARY_FORBIDDEN');
            }
            $count = substr_count($contents, $old);
            if ($count === 0) {
                return ServiceResult::fail('old text was not found. Copy an exact snippet from the file.', 'NOT_FOUND');
            }
            if (!$replaceAll && $count > 1) {
                return ServiceResult::fail(
                    'old text matches ' . $count . ' times. Pass replace_all true, or a longer unique snippet.',
                    'AMBIGUOUS',
                    [(string) $count],
                );
            }
            $updated = $replaceAll ? str_replace($old, $new, $contents) : self::replaceFirst($contents, $old, $new);
            $result = $this->persistTextFile($path, $real, $updated, 'edit_file');
            if ($result['success'] && is_array($result['data'])) {
                $result['data']['replacements'] = $replaceAll ? $count : 1;
            }

            return $result;
        } catch (PathGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'PATH_DENIED');
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function search(array $args): array
    {
        try {
            $query = (string) ($args['query'] ?? '');
            $path = (string) ($args['path'] ?? '.');
            $needles = SearchQuery::needles($query);
            if ($needles === []) {
                return ServiceResult::fail('Query is required.', 'VALIDATION_ERROR');
            }
            $real = $this->pathGuard->resolveExisting($path);
            $hits = [];
            if (is_file($real)) {
                $this->collectSearchHits($real, $needles, $hits, 50);
            } elseif (is_dir($real)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                        continue;
                    }
                    if (count($hits) >= 50) {
                        break;
                    }
                    $this->collectSearchHits($file->getPathname(), $needles, $hits, 50);
                }
            }
            $this->logger->log('search', $path, count($hits));

            $note = $hits === []
                ? 'No matches. Search is literal text (| means OR), not a regex. If you already have the file, call write_file instead of searching again.'
                : 'Search results (literal text; | means OR, not a regex)';

            return ServiceResult::ok($note, ['hits' => $hits, 'truncated' => count($hits) >= 50]);
        } catch (PathGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'PATH_DENIED');
        } catch (\Throwable $e) {
            return ServiceResult::fail('Search failed: ' . $e->getMessage(), 'SEARCH_FAILED');
        }
    }

    /**
     * @param list<string> $needles
     * @param list<array{path: string, line: int, text: string}> $hits
     */
    private function collectSearchHits(string $absolute, array $needles, array &$hits, int $limit): void
    {
        $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
        if (!in_array($ext, self::SEARCH_EXTS, true)) {
            return;
        }
        $this->pathGuard->assertUnderStaging($absolute);
        $contents = @file_get_contents($absolute);
        if (!is_string($contents) || !SearchQuery::haystackHasAny($contents, $needles)) {
            return;
        }
        $rel = substr($absolute, strlen($this->pathGuard->stagingRoot()) + 1);
        foreach (explode("\n", $contents) as $i => $line) {
            if (!SearchQuery::haystackHasAny($line, $needles)) {
                continue;
            }
            $hits[] = ['path' => $rel, 'line' => $i + 1, 'text' => mb_substr(trim($line), 0, 200)];
            if (count($hits) >= $limit) {
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function mkdir(array $args): array
    {
        try {
            $path = (string) ($args['path'] ?? '');
            $target = $this->pathGuard->resolveForWrite($path);
            if (is_dir($target)) {
                return ServiceResult::ok('Directory already exists', ['path' => $path]);
            }
            if (!mkdir($target, 0775, true) && !is_dir($target)) {
                return ServiceResult::fail('Unable to create directory.', 'MKDIR_FAILED');
            }
            $this->logger->log('mkdir', $path, 0);

            return ServiceResult::ok('Created directory', ['path' => $path]);
        } catch (PathGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'PATH_DENIED');
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function rename(array $args): array
    {
        try {
            $from = (string) ($args['from'] ?? $args['path'] ?? '');
            $to = (string) ($args['to'] ?? '');
            $src = $this->pathGuard->resolveExisting($from);
            $dest = $this->pathGuard->resolveForWrite($to);
            if (!rename($src, $dest)) {
                return ServiceResult::fail('Rename failed.', 'RENAME_FAILED');
            }
            $this->logger->log('rename', $from . ' -> ' . $to, 0);

            return ServiceResult::ok('Renamed', ['from' => $from, 'to' => $to]);
        } catch (PathGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'PATH_DENIED');
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function delete(array $args): array
    {
        try {
            $path = (string) ($args['path'] ?? '');
            $real = $this->pathGuard->resolveExisting($path);
            if ($real === $this->pathGuard->stagingRoot()) {
                return ServiceResult::fail('Cannot delete the staging root.', 'PATH_DENIED');
            }
            if (is_dir($real)) {
                if (!$this->deleteTree($real)) {
                    return ServiceResult::fail('Delete failed.', 'DELETE_FAILED');
                }
            } elseif (!unlink($real)) {
                return ServiceResult::fail('Delete failed.', 'DELETE_FAILED');
            }
            $this->logger->log('delete', $path, 0);

            return ServiceResult::ok('Deleted', ['path' => $path]);
        } catch (PathGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'PATH_DENIED');
        }
    }

    /**
     * @param list<array{path: string, type: string}> $entries
     */
    private function walk(string $dir, string $root, int $level, int $maxDepth, int $cap, array &$entries, bool &$truncated): void
    {
        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (count($entries) >= $cap) {
                $truncated = true;

                return;
            }
            $full = $dir . '/' . $item;
            $rel = substr($full, strlen($root) + 1);
            $type = is_dir($full) ? 'dir' : 'file';
            $entries[] = ['path' => $rel, 'type' => $type];
            if ($type === 'dir' && $level < $maxDepth) {
                $this->walk($full, $root, $level + 1, $maxDepth, $cap, $entries, $truncated);
            }
        }
    }

    private function looksBinaryFile(string $path): bool
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $chunk = fread($fh, 800);
        fclose($fh);

        return is_string($chunk) && str_contains($chunk, "\0");
    }

    private function deleteTree(string $dir): bool
    {
        $items = scandir($dir);
        if (!is_array($items)) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . '/' . $item;
            $this->pathGuard->assertUnderStaging($full);
            if (is_dir($full)) {
                if (!$this->deleteTree($full)) {
                    return false;
                }
            } elseif (!unlink($full)) {
                return false;
            }
        }

        return rmdir($dir);
    }

    /**
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    private function persistTextFile(string $path, string $target, string $content, string $tool): array
    {
        $dir = dirname($target);
        if (!is_dir($dir)) {
            return ServiceResult::fail('Parent directory does not exist.', 'NO_PARENT');
        }
        if (is_file($target)) {
            $this->revisions->savePrevious($target, (string) file_get_contents($target));
        }
        $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content) === false) {
            return ServiceResult::fail('Write failed.', 'WRITE_FAILED');
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);

            return ServiceResult::fail('Unable to finalize write.', 'WRITE_FAILED');
        }
        $this->logger->log($tool, $path, strlen($content));
        $message = $tool === 'edit_file' ? 'Edited file' : 'Wrote file';

        return ServiceResult::ok($message, ['path' => $path, 'bytes' => strlen($content)]);
    }

    private static function replaceFirst(string $haystack, string $needle, string $replace): string
    {
        $pos = strpos($haystack, $needle);
        if ($pos === false) {
            return $haystack;
        }

        return substr($haystack, 0, $pos) . $replace . substr($haystack, $pos + strlen($needle));
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }
        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
