<?php

declare(strict_types=1);

namespace App\DAO;

final class JsonlWriter
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function append(array $row): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $line = json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }

    public function sumFloat(string $key): float
    {
        if (!is_readable($this->path)) {
            return 0.0;
        }
        $sum = 0.0;
        $fh = fopen($this->path, 'r');
        if ($fh === false) {
            return 0.0;
        }
        while (($line = fgets($fh)) !== false) {
            $row = json_decode($line, true);
            if (is_array($row) && isset($row[$key]) && is_numeric($row[$key])) {
                $sum += (float) $row[$key];
            }
        }
        fclose($fh);

        return $sum;
    }
}
