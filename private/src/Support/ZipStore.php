<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Uncompressed ZIP (STORE). Avoids depending on ext-zip at runtime.
 */
final class ZipStore
{
    /** @var list<array{name: string, contents: string, mtime: int, offset: int, crc: int, size: int}> */
    private array $files = [];

    public function add(string $name, string $contents, ?int $mtime = null): void
    {
        $name = str_replace('\\', '/', $name);
        $name = ltrim($name, '/');
        if ($name === '' || str_contains($name, '..')) {
            throw new \InvalidArgumentException('Invalid zip path.');
        }
        $this->files[] = [
            'name' => $name,
            'contents' => $contents,
            'mtime' => $mtime ?? time(),
            'offset' => 0,
            'crc' => 0,
            'size' => strlen($contents),
        ];
    }

    public function bytes(): string
    {
        $local = '';
        $central = '';
        $offset = 0;
        foreach ($this->files as &$file) {
            $crc = crc32($file['contents']);
            $size = $file['size'];
            $name = $file['name'];
            $dos = $this->dosTime($file['mtime']);
            $header = pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0,
                0,
                $dos['time'],
                $dos['date'],
                $crc,
                $size,
                $size,
                strlen($name),
                0
            );
            $file['offset'] = $offset;
            $file['crc'] = $crc;
            $local .= $header . $name . $file['contents'];
            $offset += strlen($header) + strlen($name) + $size;
            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                $dos['time'],
                $dos['date'],
                $crc,
                $size,
                $size,
                strlen($name),
                0,
                0,
                0,
                0,
                0,
                $file['offset']
            ) . $name;
        }
        unset($file);
        $count = count($this->files);
        $eocd = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $count,
            $count,
            strlen($central),
            strlen($local),
            0
        );

        return $local . $central . $eocd;
    }

    /**
     * @return array{time: int, date: int}
     */
    private function dosTime(int $unix): array
    {
        $t = getdate($unix);
        $year = max(1980, min(2107, (int) $t['year']));
        $date = (($year - 1980) << 9) | ((int) $t['mon'] << 5) | (int) $t['mday'];
        $time = ((int) $t['hours'] << 11) | ((int) $t['minutes'] << 5) | intdiv((int) $t['seconds'], 2);

        return ['time' => $time, 'date' => $date];
    }
}
