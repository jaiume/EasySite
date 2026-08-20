<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\ZipStore;
use PHPUnit\Framework\TestCase;

final class ZipStoreTest extends TestCase
{
    public function testRoundTripWithNestedPaths(): void
    {
        $zip = new ZipStore();
        $zip->add('logs/tools.jsonl', "{\"tool\":\"write_file\"}\n");
        $zip->add('README.txt', "hello\n");
        $bytes = $zip->bytes();
        self::assertStringStartsWith("PK\x03\x04", $bytes);

        $tmp = sys_get_temp_dir() . '/cp-zip-' . bin2hex(random_bytes(4)) . '.zip';
        $dir = $tmp . '.out';
        try {
            file_put_contents($tmp, $bytes);
            $archive = new \ZipArchive();
            self::assertTrue($archive->open($tmp) === true);
            $archive->extractTo($dir);
            $archive->close();
            self::assertSame("{\"tool\":\"write_file\"}\n", (string) file_get_contents($dir . '/logs/tools.jsonl'));
            self::assertSame("hello\n", (string) file_get_contents($dir . '/README.txt'));
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            if (is_dir($dir)) {
                $this->rm($dir);
            }
        }
    }

    public function testRejectsParentSegments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ZipStore())->add('../secret.txt', 'nope');
    }

    private function rm(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
