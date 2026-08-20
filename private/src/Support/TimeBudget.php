<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Per-request time limits from the runtime, not config.ini.
 *
 * CLI (cron) has no proxy, so a turn can sit on the model for minutes.
 * Web uses the front proxy read timeout when that file is readable, else 60s.
 */
final class TimeBudget
{
    public function __construct(
        public readonly int $hardLimitSeconds,
        public readonly int $requestSeconds,
        public readonly int $chatSeconds,
        public readonly bool $cli,
    ) {
    }

    /**
     * @param list<string> $nginxConfPaths
     */
    public static function detect(
        ?string $sapi = null,
        ?int $phpMaxExecution = null,
        array $nginxConfPaths = [],
        string $cacheFile = '',
    ): self {
        $sapi = $sapi ?? PHP_SAPI;
        $cli = $sapi === 'cli' || $sapi === 'phpdbg';
        $php = $phpMaxExecution ?? (int) ini_get('max_execution_time');
        $paths = $nginxConfPaths === [] ? self::defaultNginxPaths() : $nginxConfPaths;
        $proxy = self::nginxReadTimeoutSeconds($paths);
        if ($proxy <= 0 && $cacheFile !== '') {
            $proxy = self::cachedProxyTimeout($cacheFile);
        }
        if ($cli && $proxy > 0 && $cacheFile !== '') {
            self::writeCache($cacheFile, $proxy);
        }
        if ($cli) {
            $hard = $php > 0 ? $php : 300;
        } elseif ($proxy > 0) {
            $hard = $proxy;
        } else {
            $hard = 60;
        }
        $hard = max(20, min(600, $hard));
        $request = max(20, $hard - 8);
        $chat = max(15, $request - 5);

        return new self($hard, $request, $chat, $cli);
    }

    public static function cachedProxyTimeout(string $file): int
    {
        if ($file === '' || !is_readable($file)) {
            return 0;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return 0;
        }
        $value = $data['proxy_read_timeout'] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    public static function writeCache(string $file, int $proxyReadTimeout): void
    {
        if ($file === '' || $proxyReadTimeout <= 0) {
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        file_put_contents($file, json_encode([
            'proxy_read_timeout' => $proxyReadTimeout,
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function applyPhpLimit(): void
    {
        @set_time_limit($this->hardLimitSeconds);
    }

    /**
     * @param list<string> $paths
     */
    public static function nginxReadTimeoutSeconds(array $paths): int
    {
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || !is_readable($path)) {
                continue;
            }
            $text = @file_get_contents($path);
            if (!is_string($text) || $text === '') {
                continue;
            }
            if (preg_match('/proxy_read_timeout\s+(\d+)\s*s/i', $text, $m) === 1) {
                return (int) $m[1];
            }
            if (preg_match('/fastcgi_read_timeout\s+(\d+)\s*s/i', $text, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /**
     * @return list<string>
     */
    public static function defaultNginxPaths(): array
    {
        return [
            '/etc/nginx/nginx.conf',
        ];
    }
}
