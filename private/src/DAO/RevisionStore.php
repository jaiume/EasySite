<?php

declare(strict_types=1);

namespace App\DAO;

final class RevisionStore
{
    public function __construct(
        private readonly string $dir,
    ) {
    }

    public function savePrevious(string $absolutePath, string $contents): void
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0770, true);
        }
        $stamp = date('YmdHis');
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $absolutePath) ?? 'file';
        $name = $stamp . '_' . substr(hash('sha256', $absolutePath), 0, 12) . '_' . basename($safe);
        file_put_contents($this->dir . '/' . $name, $contents);
    }
}
