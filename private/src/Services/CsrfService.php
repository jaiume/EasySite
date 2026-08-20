<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;

final class CsrfService
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function token(): string
    {
        $ttl = $this->config->int('security.csrf_ttl_seconds', 28800);
        $existing = $_SESSION['csrf']['token'] ?? null;
        $at = (int) ($_SESSION['csrf']['at'] ?? 0);
        if (is_string($existing) && $existing !== '' && ($at + $ttl) > time()) {
            return $existing;
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf'] = ['token' => $token, 'at' => time()];

        return $token;
    }

    public function validate(?string $token): bool
    {
        $expected = $_SESSION['csrf']['token'] ?? null;
        if (!is_string($expected) || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }
}
