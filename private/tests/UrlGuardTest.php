<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\DnsResolver;
use App\Support\UrlGuard;
use App\Support\UrlGuardException;
use PHPUnit\Framework\TestCase;

final class FakeDns implements DnsResolver
{
    /** @param array<string, list<string>> $map */
    public function __construct(private array $map)
    {
    }

    public function resolve(string $host): array
    {
        return $this->map[$host] ?? [];
    }
}

final class UrlGuardTest extends TestCase
{
    public function testAllowsPublicHttps(): void
    {
        $guard = new UrlGuard(new FakeDns(['example.com' => ['93.184.216.34']]));
        $parsed = $guard->assertSafeUrl('https://example.com/about');
        self::assertSame('example.com', $parsed['host']);
        self::assertSame('/about', $parsed['path']);
    }

    public function testRejectsHttp(): void
    {
        $guard = new UrlGuard(new FakeDns(['example.com' => ['93.184.216.34']]));
        $this->expectException(UrlGuardException::class);
        $guard->assertSafeUrl('http://example.com/');
    }

    public function testRejectsLocalhost(): void
    {
        $guard = new UrlGuard(new FakeDns(['localhost' => ['127.0.0.1']]));
        $this->expectException(UrlGuardException::class);
        $guard->assertSafeUrl('https://localhost/');
    }

    public function testRejectsPrivateIpv4(): void
    {
        $guard = new UrlGuard(new FakeDns(['evil.example.com' => ['10.0.0.5']]));
        $this->expectException(UrlGuardException::class);
        $guard->assertSafeUrl('https://evil.example.com/secret');
    }

    public function testRejectsMetadataIp(): void
    {
        $guard = new UrlGuard(new FakeDns(['metadata' => ['169.254.169.254']]));
        $this->expectException(UrlGuardException::class);
        $guard->assertSafeUrl('https://metadata/latest');
    }

    public function testRejectsLiteralPrivateIpHost(): void
    {
        $guard = new UrlGuard(new FakeDns([]));
        $this->expectException(UrlGuardException::class);
        $guard->assertSafeUrl('https://127.0.0.1/');
    }

    public function testRejectsUserinfo(): void
    {
        $guard = new UrlGuard(new FakeDns(['example.com' => ['93.184.216.34']]));
        $this->expectException(UrlGuardException::class);
        $guard->assertSafeUrl('https://user:pass@example.com/');
    }

    public function testBlockedIpHelper(): void
    {
        $guard = new UrlGuard(new FakeDns([]));
        self::assertTrue($guard->isBlockedIp('127.0.0.1'));
        self::assertTrue($guard->isBlockedIp('10.1.2.3'));
        self::assertTrue($guard->isBlockedIp('192.168.1.1'));
        self::assertTrue($guard->isBlockedIp('169.254.169.254'));
        self::assertTrue($guard->isBlockedIp('::1'));
        self::assertFalse($guard->isBlockedIp('93.184.216.34'));
        self::assertFalse($guard->isBlockedIp('1.1.1.1'));
    }
}
