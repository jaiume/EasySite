<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\ServiceResult;

final class PublishService
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function publish(): array
    {
        $staging = $this->config->stagingRoot();
        $live = $this->config->liveRoot();
        if (!is_dir($staging) || !is_dir($live)) {
            return ServiceResult::fail('Staging or live directory is missing.', 'MISSING_DIR');
        }
        $snapshot = $this->snapshotLive();
        if (!$snapshot['success']) {
            return $snapshot;
        }
        $copied = $this->copyStagingToLive($staging, $live);
        if (!$copied['success']) {
            return $copied;
        }
        $this->pruneSnapshots();

        return ServiceResult::ok('Published staging to live.', [
            'snapshot' => $snapshot['data']['dir'] ?? null,
        ]);
    }

    /**
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function rollback(): array
    {
        $live = $this->config->liveRoot();
        $latest = $this->latestSnapshot();
        if ($latest === null) {
            return ServiceResult::fail('No snapshot to restore.', 'NO_SNAPSHOT');
        }
        $copied = $this->copySnapshotToLive($latest, $live);
        if (!$copied['success']) {
            return $copied;
        }

        return ServiceResult::ok('Rolled back to previous live snapshot.', ['snapshot' => basename($latest)]);
    }

    /**
     * @return list<string>
     */
    public function reservedNames(): array
    {
        return ['cp', 'staging'];
    }

    /**
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function snapshotLive(): array
    {
        $live = $this->config->liveRoot();
        $dir = $this->backupRoot() . '/' . date('YmdHis');
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            return ServiceResult::fail('Unable to create snapshot directory.', 'SNAPSHOT_FAILED');
        }
        $this->copyTree($live, $dir, true);
        $this->pruneSnapshots();

        return ServiceResult::ok('Snapshot saved', ['dir' => $dir]);
    }

    public function isReservedName(string $name): bool
    {
        return in_array($name, $this->reservedNames(), true);
    }

    private function copyStagingToLive(string $staging, string $live): array
    {
        $rsync = $this->tryRsync($staging . '/', $live . '/', true);
        if ($rsync) {
            return ServiceResult::ok('Copied with rsync');
        }
        $this->deleteLiveExtras($live, $staging);
        $this->copyTree($staging, $live, false);

        return ServiceResult::ok('Copied with PHP');
    }

    private function copySnapshotToLive(string $snapshot, string $live): array
    {
        $this->deleteLiveExtras($live, $snapshot);
        $this->copyTree($snapshot, $live, false);

        return ServiceResult::ok('Restored snapshot');
    }

    private function tryRsync(string $source, string $dest, bool $delete): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $cmd = ['rsync', '-a'];
        if ($delete) {
            $cmd[] = '--delete';
        }
        foreach ($this->reservedNames() as $name) {
            $cmd[] = '--exclude=' . $name;
        }
        $cmd[] = '--exclude=.htpasswd';
        $cmd[] = $source;
        $cmd[] = $dest;
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return $code === 0;
    }

    private function copyTree(string $from, string $to, bool $snapshottingLive): void
    {
        $from = rtrim($from, '/');
        $to = rtrim($to, '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $rel = substr($item->getPathname(), strlen($from) + 1);
            $first = explode('/', str_replace('\\', '/', $rel))[0];
            if ($this->isReservedName($first) || $first === '.htpasswd') {
                continue;
            }
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

    private function deleteLiveExtras(string $live, string $source): void
    {
        $live = rtrim($live, '/');
        $source = rtrim($source, '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($live, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $rel = substr($item->getPathname(), strlen($live) + 1);
            $first = explode('/', str_replace('\\', '/', $rel))[0];
            if ($this->isReservedName($first)) {
                continue;
            }
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

    private function backupRoot(): string
    {
        return $this->config->baseDir() . '/var/data/backups';
    }

    private function latestSnapshot(): ?string
    {
        $root = $this->backupRoot();
        if (!is_dir($root)) {
            return null;
        }
        $dirs = glob($root . '/*', GLOB_ONLYDIR) ?: [];
        rsort($dirs, SORT_STRING);

        return $dirs[0] ?? null;
    }

    private function pruneSnapshots(): void
    {
        $keep = $this->config->int('security.backup_keep', 10);
        $root = $this->backupRoot();
        $dirs = glob($root . '/*', GLOB_ONLYDIR) ?: [];
        rsort($dirs, SORT_STRING);
        foreach (array_slice($dirs, $keep) as $old) {
            $this->rmTree($old);
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
