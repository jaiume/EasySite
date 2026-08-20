<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\ServiceResult;

final class CheckpointService
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function create(): string
    {
        $id = bin2hex(random_bytes(8));
        $dest = $this->dir($id);
        if (!is_dir($dest) && !mkdir($dest, 0770, true) && !is_dir($dest)) {
            throw new \RuntimeException('Unable to create checkpoint.');
        }
        $this->mirror($this->config->stagingRoot(), $dest);

        return $id;
    }

    /**
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function restore(string $id): array
    {
        $id = $this->safeId($id);
        if ($id === '') {
            return ServiceResult::fail('Invalid checkpoint.', 'INVALID_CHECKPOINT');
        }
        $src = $this->dir($id);
        if (!is_dir($src)) {
            return ServiceResult::fail('Checkpoint is missing.', 'MISSING_CHECKPOINT');
        }
        $staging = $this->config->stagingRoot();
        if (!is_dir($staging)) {
            mkdir($staging, 0775, true);
        }
        $this->deleteExtras($staging, $src);
        $this->copyTree($src, $staging);

        return ServiceResult::ok('Draft restored.', ['checkpoint_id' => $id]);
    }

    /**
     * @param list<string> $keepIds
     */
    public function deleteUnused(array $keepIds): void
    {
        $keep = [];
        foreach ($keepIds as $id) {
            $safe = $this->safeId($id);
            if ($safe !== '') {
                $keep[$safe] = true;
            }
        }
        $root = $this->root();
        if (!is_dir($root)) {
            return;
        }
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (!isset($keep[$id])) {
                $this->rmTree($dir);
            }
        }
    }

    public function deleteAll(): void
    {
        $root = $this->root();
        if (!is_dir($root)) {
            return;
        }
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $this->rmTree($dir);
        }
    }

    public function exists(string $id): bool
    {
        $id = $this->safeId($id);

        return $id !== '' && is_dir($this->dir($id));
    }

    private function root(): string
    {
        return $this->config->baseDir() . '/var/data/checkpoints';
    }

    private function dir(string $id): string
    {
        return $this->root() . '/' . $id;
    }

    private function safeId(string $id): string
    {
        return preg_replace('/[^a-f0-9]/', '', strtolower($id)) ?? '';
    }

    private function mirror(string $from, string $to): void
    {
        if (!is_dir($from)) {
            return;
        }
        $this->copyTree($from, $to);
    }

    private function copyTree(string $from, string $to): void
    {
        $from = rtrim($from, '/');
        $to = rtrim($to, '/');
        if (!is_dir($from)) {
            return;
        }
        if (!is_dir($to)) {
            mkdir($to, 0770, true);
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $rel = substr($item->getPathname(), strlen($from) + 1);
            $dest = $to . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0775, true);
                }
            } else {
                $dir = dirname($dest);
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                copy($item->getPathname(), $dest);
            }
        }
    }

    private function deleteExtras(string $dest, string $source): void
    {
        $dest = rtrim($dest, '/');
        $source = rtrim($source, '/');
        if (!is_dir($dest)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dest, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $rel = substr($item->getPathname(), strlen($dest) + 1);
            $counterpart = $source . '/' . $rel;
            if (file_exists($counterpart)) {
                continue;
            }
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
