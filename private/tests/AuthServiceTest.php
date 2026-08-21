<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\AuthService;
use App\Support\Config;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cp-auth-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0777, true);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
        $_SESSION = [];
    }

    public function testChangePasswordUpdatesConfigAndLogin(): void
    {
        $auth = $this->authFrom('[auth]
username = "admin"
password = "changeme"
');
        $_SESSION['auth'] = ['user' => 'admin', 'at' => 1];
        $result = $auth->changePassword('changeme', 'new-secret', 'new-secret');
        self::assertTrue($result['success']);
        self::assertSame('Password changed.', $result['message']);
        self::assertSame('new-secret', $this->passwordOnDisk());
        self::assertFalse($auth->attempt('admin', 'changeme')['success']);
        self::assertTrue($auth->attempt('admin', 'new-secret')['success']);
    }

    public function testChangePasswordRejectsWrongCurrent(): void
    {
        $auth = $this->authFrom('[auth]
username = "admin"
password = "changeme"
');
        $result = $auth->changePassword('nope', 'new-secret', 'new-secret');
        self::assertFalse($result['success']);
        self::assertSame('Current password is incorrect.', $result['message']);
        self::assertSame('changeme', $this->passwordOnDisk());
    }

    public function testChangePasswordRejectsMismatchAndShortAndSame(): void
    {
        $auth = $this->authFrom('[auth]
username = "admin"
password = "changeme"
');
        $mismatch = $auth->changePassword('changeme', 'new-secret', 'other-secret');
        self::assertFalse($mismatch['success']);
        self::assertSame('New password and confirmation do not match.', $mismatch['message']);

        $short = $auth->changePassword('changeme', 'short', 'short');
        self::assertFalse($short['success']);
        self::assertSame('New password must be at least 8 characters.', $short['message']);

        $same = $auth->changePassword('changeme', 'changeme', 'changeme');
        self::assertFalse($same['success']);
        self::assertSame('Choose a different password from the current one.', $same['message']);
        self::assertSame('changeme', $this->passwordOnDisk());
    }

    private function authFrom(string $ini): AuthService
    {
        file_put_contents($this->root . '/config/config.ini', $ini);

        return new AuthService(new Config($this->root . '/config/config.ini', $this->root));
    }

    private function passwordOnDisk(): string
    {
        return (new Config($this->root . '/config/config.ini', $this->root))->string('auth.password');
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
