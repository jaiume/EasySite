<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\SecretRedactor;
use App\Support\TimeBudget;

final class LogExportService
{
    private const MAX_FILE_BYTES = 1048576;

    public function __construct(
        private readonly Config $config,
        private readonly TimeBudget $timeBudget,
    ) {
    }

    /**
     * @return list<array{label: string, exists: bool, bytes: int, size: string, modified: ?string}>
     */
    public function summaries(): array
    {
        $out = [];
        foreach ($this->sources() as $source) {
            $exists = is_file($source['path']);
            $bytes = $exists ? (int) (@filesize($source['path']) ?: 0) : 0;
            $mtime = $exists ? @filemtime($source['path']) : false;
            $out[] = [
                'label' => $source['label'],
                'exists' => $exists,
                'bytes' => $bytes,
                'size' => $this->humanSize($bytes),
                'modified' => is_int($mtime) && $mtime > 0 ? date('Y-m-d H:i', $mtime) : null,
            ];
        }

        return $out;
    }

    /**
     * @return array{filename: string, bytes: string}
     */
    public function build(): array
    {
        $stamp = date('Y-m-d-His');
        $filename = 'cp-logs-' . $stamp . '.json';
        $included = [];
        $missing = [];
        $truncated = [];

        $iniPath = $this->config->baseDir() . '/config/config.ini';
        $configIni = null;
        if (is_readable($iniPath)) {
            $configIni = SecretRedactor::text((string) file_get_contents($iniPath));
            $included[] = 'config_ini';
        } else {
            $missing[] = 'config_ini';
        }

        $sections = [];
        foreach ($this->sources() as $source) {
            if (!is_readable($source['path'])) {
                $missing[] = $source['key'];
                $sections[$source['key']] = null;
                continue;
            }
            $raw = SecretRedactor::text($this->readCapped($source['path']));
            $wasTruncated = str_starts_with($raw, '[truncated;');
            if ($wasTruncated) {
                $truncated[] = $source['key'];
                $nl = strpos($raw, "\n");
                $raw = $nl === false ? '' : substr($raw, $nl + 1);
            }
            $sections[$source['key']] = $this->decodeSection($source['kind'], $raw);
            $included[] = $source['key'];
        }

        $payload = [
            'generated_at' => date('c'),
            'filename' => $filename,
            'note' => 'API key, passwords, and bearer tokens were removed. Attach this JSON when reporting a problem.',
            'included' => $included,
            'missing' => $missing,
            'truncated' => $truncated,
            'environment' => $this->environment(),
            'config_ini' => $configIni,
            'tools' => $sections['tools'] ?? null,
            'spend' => $sections['spend'] ?? null,
            'app_log' => $sections['app_log'] ?? null,
            'chat' => $sections['chat'] ?? null,
            'pending' => $sections['pending'] ?? null,
            'runtime_timeouts' => $sections['runtime_timeouts'] ?? null,
            'runs' => $this->runListing(),
        ];
        $included[] = 'environment';
        $included[] = 'runs';
        $payload['included'] = array_values(array_unique($included));

        return [
            'filename' => $filename,
            'bytes' => $this->json($payload),
        ];
    }

    /**
     * @return list<array{label: string, key: string, kind: string, path: string}>
     */
    private function sources(): array
    {
        $base = $this->config->baseDir();

        return [
            [
                'label' => 'Tool log',
                'key' => 'tools',
                'kind' => 'jsonl',
                'path' => $this->config->resolveVarPath($this->config->string('logging.tool_log', 'var/data/logs/tools.jsonl')),
            ],
            [
                'label' => 'Spend log',
                'key' => 'spend',
                'kind' => 'jsonl',
                'path' => $this->config->resolveVarPath($this->config->string('logging.spend_log', 'var/data/logs/spend.jsonl')),
            ],
            [
                'label' => 'App errors',
                'key' => 'app_log',
                'kind' => 'text',
                'path' => $this->config->resolveVarPath($this->config->string('logging.app_log', 'var/data/logs/app.log')),
            ],
            [
                'label' => 'Current chat',
                'key' => 'chat',
                'kind' => 'json',
                'path' => $base . '/var/data/chats/current.json',
            ],
            [
                'label' => 'Pending turn',
                'key' => 'pending',
                'kind' => 'json',
                'path' => $base . '/var/data/chats/pending.json',
            ],
            [
                'label' => 'Runtime timeouts',
                'key' => 'runtime_timeouts',
                'kind' => 'json',
                'path' => $base . '/var/data/runtime-timeouts.json',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(): array
    {
        $base = $this->config->baseDir();
        $free = @disk_free_space($base);
        $total = @disk_total_space($base);

        return [
            'generated_at' => date('c'),
            'host' => (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''),
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS,
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'error_log' => (string) ini_get('error_log'),
            'log_errors' => (string) ini_get('log_errors'),
            'time_budget' => [
                'hard_limit_seconds' => $this->timeBudget->hardLimitSeconds,
                'request_seconds' => $this->timeBudget->requestSeconds,
                'chat_seconds' => $this->timeBudget->chatSeconds,
                'cli' => $this->timeBudget->cli,
            ],
            'paths' => [
                'base' => $base,
                'docroot' => $this->config->docroot(),
                'staging' => $this->config->stagingRoot(),
                'live' => $this->config->liveRoot(),
            ],
            'openrouter' => [
                'chat_model' => $this->config->string('openrouter.default_chat_model'),
                'image_model' => $this->config->string('openrouter.default_image_model'),
                'max_tool_rounds' => $this->config->int('openrouter.max_tool_rounds', 40),
                'monthly_spend_cap' => $this->config->float('openrouter.monthly_spend_cap', 10.0),
                'has_api_key' => trim($this->config->string('openrouter.api_key')) !== '',
            ],
            'extensions' => get_loaded_extensions(),
            'disk' => [
                'free_bytes' => is_int($free) || is_float($free) ? (int) $free : null,
                'total_bytes' => is_int($total) || is_float($total) ? (int) $total : null,
            ],
        ];
    }

    /**
     * @return list<array{name: string, bytes: int, modified: ?string}>
     */
    private function runListing(): array
    {
        $dir = $this->config->baseDir() . '/var/data/runs';
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $mtime = @filemtime($file);
            $out[] = [
                'name' => basename($file),
                'bytes' => (int) (@filesize($file) ?: 0),
                'modified' => is_int($mtime) ? date('c', $mtime) : null,
            ];
        }

        return $out;
    }

    private function decodeSection(string $kind, string $raw): mixed
    {
        if ($kind === 'text') {
            return $raw;
        }
        if ($kind === 'jsonl') {
            $rows = [];
            foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row = json_decode($line, true);
                $rows[] = is_array($row) ? $row : $line;
            }

            return $rows;
        }
        $decoded = json_decode($raw, true);

        return $decoded !== null || $raw === '' || $raw === 'null' ? $decoded : $raw;
    }

    private function readCapped(string $path): string
    {
        $size = @filesize($path);
        if (!is_int($size) || $size <= 0) {
            return '';
        }
        if ($size <= self::MAX_FILE_BYTES) {
            return (string) file_get_contents($path);
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return '';
        }
        fseek($fh, -$this->tailBytes($size), SEEK_END);
        $data = (string) stream_get_contents($fh);
        fclose($fh);
        $nl = strpos($data, "\n");
        if ($nl !== false) {
            $data = substr($data, $nl + 1);
        }

        return '[truncated; showing last ' . self::MAX_FILE_BYTES . ' bytes of ' . $size . "]\n" . $data;
    }

    private function tailBytes(int $size): int
    {
        return min(self::MAX_FILE_BYTES, $size);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        return number_format($bytes / 1024, 1) . ' KB';
    }
}
