<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\JsonlWriter;

final class ToolLogger
{
    public function __construct(
        private readonly JsonlWriter $log,
    ) {
    }

    public function log(string $tool, ?string $path, int $bytes, float $cost = 0.0, ?string $url = null): void
    {
        $this->log->append([
            'time' => date('c'),
            'tool' => $tool,
            'path' => $path,
            'url' => $url,
            'bytes' => $bytes,
            'cost' => $cost,
        ]);
    }
}
