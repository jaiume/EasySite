<?php

declare(strict_types=1);

namespace App\Support;

final class Config
{
    /** @var array<string, array<string, mixed>> */
    private array $data;

    public function __construct(
        private readonly string $iniPath,
        private readonly string $baseDir,
    ) {
        $parsed = parse_ini_file($this->iniPath, true, INI_SCANNER_TYPED);
        if (!is_array($parsed)) {
            throw new \RuntimeException('Unable to parse config.ini');
        }
        $this->data = $parsed;
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (str_contains($key, '.')) {
            [$section, $name] = explode('.', $key, 2);

            return $this->data[$section][$name] ?? $default;
        }

        foreach ($this->data as $section) {
            if (is_array($section) && array_key_exists($key, $section)) {
                return $section[$key];
            }
        }

        return $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function float(string $key, float $default = 0.0): float
    {
        return (float) $this->get($key, $default);
    }

    public function string(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    public function docroot(): string
    {
        $configured = trim($this->string('paths.docroot'));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return dirname($this->baseDir) . '/public_html';
    }

    public function stagingRoot(): string
    {
        $configured = trim($this->string('paths.staging_root'));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return $this->docroot() . '/staging';
    }

    public function liveRoot(): string
    {
        $configured = trim($this->string('paths.live_root'));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return $this->docroot();
    }

    public function baseUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $host = is_string($host) ? trim($host) : '';
        if ($host === '') {
            return 'http://localhost';
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        return ($https ? 'https' : 'http') . '://' . $host;
    }

    public function writeString(string $section, string $key, string $value): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $section) || !preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException('Invalid config key.');
        }
        if (!is_readable($this->iniPath) || !is_writable($this->iniPath)) {
            throw new \RuntimeException('config.ini is not writable.');
        }
        $raw = (string) file_get_contents($this->iniPath);
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        if ($lines !== [] && $lines[count($lines) - 1] === '') {
            array_pop($lines);
        }
        $inSection = false;
        $written = false;
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*\[(.+)]\s*$/', $line, $m) === 1) {
                if ($inSection && !$written) {
                    $out[] = $key . ' = ' . $this->quoteIni($value);
                    $written = true;
                }
                $inSection = $m[1] === $section;
                $out[] = $line;
                continue;
            }
            if ($inSection && preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $line) === 1) {
                $out[] = $key . ' = ' . $this->quoteIni($value);
                $written = true;
                continue;
            }
            $out[] = $line;
        }
        if (!$written) {
            if (!$inSection) {
                if ($out !== [] && end($out) !== '') {
                    $out[] = '';
                }
                $out[] = '[' . $section . ']';
            }
            $out[] = $key . ' = ' . $this->quoteIni($value);
        }
        $payload = implode("\n", $out) . "\n";
        if (file_put_contents($this->iniPath, $payload, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write config.ini.');
        }
        $this->data[$section][$key] = $value;
    }

    private function quoteIni(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    public function resolveVarPath(string $relative): string
    {
        if ($relative === '' || $relative[0] === '/') {
            return $relative;
        }

        return $this->baseDir . '/' . ltrim($relative, '/');
    }
}
