<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;

final class RateLimitService
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function hit(string $bucket, string $id): bool
    {
        $max = $this->config->int('security.login_max_attempts', 8);
        $window = $this->config->int('security.login_window_seconds', 900);
        $path = $this->config->baseDir() . '/var/data/ratelimit/' . preg_replace('/[^a-z0-9_-]/', '', $bucket) . '.json';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $data = [];
        if (is_readable($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        $now = time();
        $key = hash('sha256', $id);
        $times = $data[$key] ?? [];
        if (!is_array($times)) {
            $times = [];
        }
        $times = array_values(array_filter($times, static fn ($t) => is_int($t) && $t > $now - $window));
        if (count($times) >= $max) {
            $data[$key] = $times;
            file_put_contents($path, json_encode($data), LOCK_EX);

            return false;
        }
        $times[] = $now;
        $data[$key] = $times;
        file_put_contents($path, json_encode($data), LOCK_EX);

        return true;
    }
}
