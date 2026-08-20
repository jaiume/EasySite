<?php

declare(strict_types=1);

namespace App\Tests;

use App\DAO\JsonlWriter;
use App\DAO\RevisionStore;
use App\Services\FileToolService;
use App\Services\ToolLogger;
use App\Support\Config;
use App\Support\PathGuard;
use PHPUnit\Framework\TestCase;

final class FileSearchTest extends TestCase
{
    private string $root;
    private FileToolService $files;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cp-search-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public_html/staging/css', 0777, true);
        mkdir($this->root . '/private/var/data', 0777, true);
        file_put_contents($this->root . '/public_html/staging/css/site.css', ":root {\n    --accent: #0d6b4c;\n    --ink: #17211c;\n}\n");
        file_put_contents($this->root . '/public_html/staging/includes-header.php', '<header class="site-header">');
        mkdir($this->root . '/public_html/staging/includes', 0777, true);
        file_put_contents($this->root . '/public_html/staging/includes/header.php', '<a class="logo" href="/">Home</a>');
        $ini = <<<INI
[paths]
docroot = "{$this->root}/public_html"
staging_root = "{$this->root}/public_html/staging"
[security]
list_dir_max_entries = 200
INI;
        $iniPath = $this->root . '/config.ini';
        file_put_contents($iniPath, $ini);
        $config = new Config($iniPath, $this->root . '/private');
        $guard = new PathGuard(
            $this->root . '/public_html/staging',
            $this->root . '/public_html',
            $this->root . '/private',
        );
        $this->files = new FileToolService(
            $guard,
            $config,
            new RevisionStore($this->root . '/private/var/data/revisions'),
            new ToolLogger(new JsonlWriter($this->root . '/private/var/data/tools.jsonl')),
        );
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testSearchAFileDoesNotThrow(): void
    {
        $result = $this->files->search([
            'path' => 'css/site.css',
            'query' => '0d6b|08533|#0d',
        ]);
        self::assertTrue($result['success'], $result['message']);
        $hits = $result['data']['hits'] ?? [];
        self::assertNotSame([], $hits);
        self::assertSame('css/site.css', $hits[0]['path'] ?? null);
    }

    public function testSearchDirectoryFindsHeaderClass(): void
    {
        $result = $this->files->search([
            'path' => 'includes/header.php',
            'query' => 'class=',
        ]);
        self::assertTrue($result['success'], $result['message']);
        $hits = $result['data']['hits'] ?? [];
        self::assertNotSame([], $hits);
    }

    public function testNoMatchTellsModelToWrite(): void
    {
        $result = $this->files->search([
            'path' => 'css/site.css',
            'query' => 'this-string-is-not-in-the-file',
        ]);
        self::assertTrue($result['success'], $result['message']);
        self::assertSame([], $result['data']['hits'] ?? null);
        self::assertStringContainsString('write_file', $result['message']);
    }

    public function testEditFileReplacesOnce(): void
    {
        $result = $this->files->editFile([
            'path' => 'css/site.css',
            'old' => '#0d6b4c',
            'new' => '#c2185b',
        ]);
        self::assertTrue($result['success'], $result['message']);
        $css = (string) file_get_contents($this->root . '/public_html/staging/css/site.css');
        self::assertStringContainsString('#c2185b', $css);
        self::assertStringNotContainsString('#0d6b4c', $css);
        self::assertSame(1, $result['data']['replacements'] ?? null);
    }

    public function testEditFileReplaceAll(): void
    {
        file_put_contents($this->root . '/public_html/staging/css/site.css', "a{color:#0d6b4c}\nb{color:#0d6b4c}\n");
        $result = $this->files->editFile([
            'path' => 'css/site.css',
            'old' => '#0d6b4c',
            'new' => '#c2185b',
            'replace_all' => true,
        ]);
        self::assertTrue($result['success'], $result['message']);
        self::assertSame(2, $result['data']['replacements'] ?? null);
        $css = (string) file_get_contents($this->root . '/public_html/staging/css/site.css');
        self::assertSame(2, substr_count($css, '#c2185b'));
    }

    public function testEditFileAmbiguousWithoutReplaceAll(): void
    {
        file_put_contents($this->root . '/public_html/staging/css/site.css', "a{color:#0d6b4c}\nb{color:#0d6b4c}\n");
        $result = $this->files->editFile([
            'path' => 'css/site.css',
            'old' => '#0d6b4c',
            'new' => '#c2185b',
        ]);
        self::assertFalse($result['success']);
        self::assertSame('AMBIGUOUS', $result['error']['code'] ?? null);
    }

    public function testEditFileMissingSnippetFails(): void
    {
        $result = $this->files->editFile([
            'path' => 'css/site.css',
            'old' => 'not-in-file',
            'new' => 'x',
        ]);
        self::assertFalse($result['success']);
        self::assertSame('NOT_FOUND', $result['error']['code'] ?? null);
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
