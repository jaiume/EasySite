<?php

declare(strict_types=1);

namespace App\DAO;

final class RunRegistry
{
    public function __construct(
        private readonly string $dir,
    ) {
    }

    public function start(): string
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0770, true);
        }
        $id = bin2hex(random_bytes(8));
        file_put_contents($this->dir . '/' . $id . '.run', (string) time());

        return $id;
    }

    public function cancel(string $id): void
    {
        $id = preg_replace('/[^a-f0-9]/', '', $id) ?? '';
        if ($id === '') {
            return;
        }
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0770, true);
        }
        file_put_contents($this->dir . '/' . $id . '.cancel', (string) time());
    }

    public function cancelActive(): void
    {
        foreach (glob($this->dir . '/*.run') ?: [] as $file) {
            $id = basename($file, '.run');
            if ($id === '') {
                continue;
            }
            $this->cancel($id);
            @unlink($file);
        }
    }

    public function isCancelled(string $id): bool
    {
        $id = preg_replace('/[^a-f0-9]/', '', $id) ?? '';
        if ($id === '') {
            return false;
        }

        return is_file($this->dir . '/' . $id . '.cancel');
    }

    public function finish(string $id): void
    {
        $id = preg_replace('/[^a-f0-9]/', '', $id) ?? '';
        if ($id === '') {
            return;
        }
        @unlink($this->dir . '/' . $id . '.run');
        @unlink($this->dir . '/' . $id . '.cancel');
    }

    public function touch(string $id): void
    {
        $id = preg_replace('/[^a-f0-9]/', '', $id) ?? '';
        if ($id === '') {
            return;
        }
        $file = $this->dir . '/' . $id . '.run';
        if (is_file($file)) {
            @touch($file);
        }
    }

    public function hasActive(int $maxAgeSeconds = 200): bool
    {
        return $this->activeId($maxAgeSeconds) !== null;
    }

    public function activeId(int $maxAgeSeconds = 200): ?string
    {
        foreach (glob($this->dir . '/*.run') ?: [] as $file) {
            if (time() - (int) filemtime($file) < $maxAgeSeconds) {
                $id = basename($file, '.run');

                return $id !== '' ? $id : null;
            }
            @unlink($file);
        }

        return null;
    }
}
