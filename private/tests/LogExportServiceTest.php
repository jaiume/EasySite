<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\LogExportService;
use App\Support\Config;
use App\Support\TimeBudget;
use PHPUnit\Framework\TestCase;

final class LogExportServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cp-logs-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/var/data/logs', 0777, true);
        mkdir($this->root . '/var/data/chats', 0777, true);
        mkdir($this->root . '/var/data/runs', 0777, true);
        file_put_contents($this->root . '/config/config.ini', <<<INI
[auth]
username = "admin"
password = "hunter2"
[openrouter]
api_key = "sk-or-v1-test-fixture"
default_chat_model = "anthropic/claude-sonnet-4.5"
default_image_model = "black-forest-labs/flux.2-pro"
monthly_spend_cap = 10.00
max_tool_rounds = 40
[logging]
tool_log = "var/data/logs/tools.jsonl"
spend_log = "var/data/logs/spend.jsonl"
app_log = "var/data/logs/app.log"
INI);
        file_put_contents($this->root . '/var/data/logs/tools.jsonl', "{\"tool\":\"write_file\",\"path\":\"index.php\"}\n");
        file_put_contents($this->root . '/var/data/logs/spend.jsonl', "{\"cost\":0.12,\"kind\":\"chat\"}\n");
        file_put_contents($this->root . '/var/data/chats/current.json', "{\"messages\":[{\"role\":\"user\",\"content\":\"hello sk-or-v1-test-fixture\"}]}\n");
        file_put_contents($this->root . '/var/data/runs/abc123.run', '1');
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testBuildIncludesLogsAndRedactsSecrets(): void
    {
        $bundle = $this->service()->build();
        self::assertStringStartsWith('cp-logs-', $bundle['filename']);
        self::assertStringEndsWith('.zip', $bundle['filename']);

        $files = $this->unzip($bundle['bytes']);
        self::assertArrayHasKey('logs/tools.jsonl', $files);
        self::assertArrayHasKey('logs/spend.jsonl', $files);
        self::assertArrayHasKey('chats/current.json', $files);
        self::assertArrayHasKey('environment.json', $files);
        self::assertArrayHasKey('config.redacted.ini', $files);
        self::assertArrayHasKey('runs.json', $files);
        self::assertStringContainsString('write_file', $files['logs/tools.jsonl']);
        self::assertStringContainsString('[REDACTED]', $files['config.redacted.ini']);
        self::assertStringNotContainsString('hunter2', $files['config.redacted.ini']);
        self::assertStringNotContainsString('sk-or-v1-test-fixture', $files['config.redacted.ini']);
        self::assertStringNotContainsString('sk-or-v1-test-fixture', $files['chats/current.json']);
        self::assertStringContainsString('abc123.run', $files['runs.json']);
        $env = json_decode($files['environment.json'], true);
        self::assertIsArray($env);
        self::assertTrue($env['openrouter']['has_api_key']);
        self::assertSame('anthropic/claude-sonnet-4.5', $env['openrouter']['chat_model']);
    }

    public function testSummariesReportExistingFiles(): void
    {
        $rows = $this->service()->summaries();
        $byLabel = [];
        foreach ($rows as $row) {
            $byLabel[$row['label']] = $row;
        }
        self::assertTrue($byLabel['Tool log']['exists']);
        self::assertGreaterThan(0, $byLabel['Tool log']['bytes']);
        self::assertStringEndsWith(' B', $byLabel['Tool log']['size']);
        self::assertFalse($byLabel['App errors']['exists']);
        self::assertFalse($byLabel['Pending turn']['exists']);
    }

    public function testBuildTruncatesOversizedLogs(): void
    {
        $path = $this->root . '/var/data/logs/tools.jsonl';
        $fh = fopen($path, 'wb');
        self::assertIsResource($fh);
        fwrite($fh, "KEEP-START\n");
        fwrite($fh, str_repeat("x", 1048576 + 64));
        fwrite($fh, "\nKEEP-END\n");
        fclose($fh);
        $files = $this->unzip($this->service()->build()['bytes']);
        self::assertStringContainsString('[truncated;', $files['logs/tools.jsonl']);
        self::assertStringContainsString('KEEP-END', $files['logs/tools.jsonl']);
        self::assertStringNotContainsString('KEEP-START', $files['logs/tools.jsonl']);
    }

    public function testBuildSucceedsWhenOptionalLogsAreMissing(): void
    {
        unlink($this->root . '/var/data/logs/tools.jsonl');
        $bundle = $this->service()->build();
        $files = $this->unzip($bundle['bytes']);
        $manifest = json_decode($files['manifest.json'], true);
        self::assertIsArray($manifest);
        self::assertContains('logs/tools.jsonl', $manifest['missing']);
        self::assertArrayNotHasKey('logs/tools.jsonl', $files);
    }

    private function service(): LogExportService
    {
        $config = new Config($this->root . '/config/config.ini', $this->root);

        return new LogExportService($config, new TimeBudget(60, 52, 47, false));
    }

    /**
     * @return array<string, string>
     */
    private function unzip(string $bytes): array
    {
        $tmp = $this->root . '/out.zip';
        $dir = $this->root . '/unzipped';
        file_put_contents($tmp, $bytes);
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($tmp) === true, 'zip could not be opened');
        $archive->extractTo($dir);
        $archive->close();
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $rel = substr($file->getPathname(), strlen($dir) + 1);
                $out[str_replace('\\', '/', $rel)] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
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
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
