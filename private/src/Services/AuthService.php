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

    /**
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function changePassword(string $current, string $new, string $confirm): array
    {
        $expected = $this->config->string('auth.password');
        if ($expected === '') {
            return ServiceResult::fail('Admin password is not configured.', 'AUTH_NOT_CONFIGURED');
        }
        if ($current === '' || !hash_equals($expected, $current)) {
            return ServiceResult::fail('Current password is incorrect.', 'AUTH_FAILED');
        }
        if ($new !== $confirm) {
            return ServiceResult::fail('New password and confirmation do not match.', 'VALIDATION_ERROR');
        }
        if (strlen($new) < 8) {
            return ServiceResult::fail('New password must be at least 8 characters.', 'VALIDATION_ERROR');
        }
        if (strlen($new) > 200) {
            return ServiceResult::fail('New password is too long.', 'VALIDATION_ERROR');
        }
        if (str_contains($new, "\n") || str_contains($new, "\r") || str_contains($new, "\0")) {
            return ServiceResult::fail('Password cannot contain line breaks.', 'VALIDATION_ERROR');
        }
        if (hash_equals($expected, $new)) {
            return ServiceResult::fail('Choose a different password from the current one.', 'VALIDATION_ERROR');
        }
        try {
            $this->config->writeString('auth', 'password', $new);
        } catch (\Throwable $e) {
            return ServiceResult::fail('Could not save the new password to config.ini.', 'WRITE_FAILED');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        if (isset($_SESSION['auth']) && is_array($_SESSION['auth'])) {
            $_SESSION['auth']['at'] = time();
        }

        return ServiceResult::ok('Password changed.');
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
