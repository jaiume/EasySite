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
        self::assertStringEndsWith('.json', $bundle['filename']);

        $data = $this->decode($bundle['bytes']);
        self::assertContains('tools', $data['included']);
        self::assertContains('spend', $data['included']);
        self::assertContains('chat', $data['included']);
        self::assertContains('config_ini', $data['included']);
        self::assertSame('write_file', $data['tools'][0]['tool'] ?? null);
        self::assertStringContainsString('[REDACTED]', (string) $data['config_ini']);
        self::assertStringNotContainsString('hunter2', (string) $data['config_ini']);
        self::assertStringNotContainsString('sk-or-v1-test-fixture', json_encode($data));
        self::assertSame('abc123.run', $data['runs'][0]['name'] ?? null);
        self::assertTrue($data['environment']['openrouter']['has_api_key']);
        self::assertSame('anthropic/claude-sonnet-4.5', $data['environment']['openrouter']['chat_model']);
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
        fwrite($fh, "{\"keep\":\"start\"}\n");
        fwrite($fh, str_repeat("x", 1048576 + 64));
        fwrite($fh, "\n{\"keep\":\"end\"}\n");
        fclose($fh);
        $data = $this->decode($this->service()->build()['bytes']);
        self::assertContains('tools', $data['truncated']);
        $tools = $data['tools'];
        self::assertIsArray($tools);
        $last = $tools[array_key_last($tools)] ?? null;
        self::assertSame(['keep' => 'end'], $last);
        self::assertNotContains(['keep' => 'start'], $tools);
    }

    public function testBuildSucceedsWhenOptionalLogsAreMissing(): void
    {
        unlink($this->root . '/var/data/logs/tools.jsonl');
        $data = $this->decode($this->service()->build()['bytes']);
        self::assertContains('tools', $data['missing']);
        self::assertNull($data['tools']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $bytes): array
    {
        $data = json_decode($bytes, true);
        self::assertIsArray($data, 'export was not valid JSON');

        return $data;
    }

    private function service(): LogExportService
    {
        $config = new Config($this->root . '/config/config.ini', $this->root);

        return new LogExportService($config, new TimeBudget(60, 52, 47, false));
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
