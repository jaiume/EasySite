<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\ServiceResult;

final class AuthService
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function attempt(string $username, string $password): array
    {
        $expectedUser = $this->config->string('auth.username');
        $expectedPass = $this->config->string('auth.password');
        if ($expectedPass === '') {
            return ServiceResult::fail('Admin password is not configured.', 'AUTH_NOT_CONFIGURED');
        }
        $userOk = hash_equals($expectedUser, $username);
        $passOk = hash_equals($expectedPass, $password);
        if (!$userOk || !$passOk) {
            return ServiceResult::fail('Invalid username or password.', 'AUTH_FAILED');
        }
        $_SESSION['auth'] = [
            'user' => $expectedUser,
            'at' => time(),
        ];

        return ServiceResult::ok('Signed in.');
    }

    public function logout(): void
    {
        unset($_SESSION['auth']);
    }

    public function check(): bool
    {
        return isset($_SESSION['auth']['user']);
    }

    public function user(): ?string
    {
        $user = $_SESSION['auth']['user'] ?? null;

        return is_string($user) ? $user : null;
    }
}
