<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\TimeBudget;
use PHPUnit\Framework\TestCase;

final class TimeBudgetTest extends TestCase
{
    public function testCliUsesFiveMinutesWhenPhpIsUnlimited(): void
    {
        $budget = TimeBudget::detect('cli', 0, []);
        self::assertTrue($budget->cli);
        self::assertSame(300, $budget->hardLimitSeconds);
        self::assertSame(292, $budget->requestSeconds);
        self::assertSame(287, $budget->chatSeconds);
    }

    public function testWebReadsNginxProxyReadTimeout(): void
    {
        $dir = sys_get_temp_dir() . '/cp-tb-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $conf = $dir . '/nginx.conf';
        file_put_contents($conf, "http {\n\tproxy_read_timeout              300s;\n}\n");
        try {
            $budget = TimeBudget::detect('fpm-fcgi', 30, [$conf]);
            self::assertFalse($budget->cli);
            self::assertSame(300, $budget->hardLimitSeconds);
            self::assertSame(292, $budget->requestSeconds);
        } finally {
            unlink($conf);
            rmdir($dir);
        }
    }

    public function testWebUsesCachedProxyTimeoutWhenNginxUnreadable(): void
    {
        $dir = sys_get_temp_dir() . '/cp-tb-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $cache = $dir . '/runtime-timeouts.json';
        TimeBudget::writeCache($cache, 300);
        try {
            $budget = TimeBudget::detect('fpm-fcgi', 30, ['/no/such/nginx.conf'], $cache);
            self::assertSame(300, $budget->hardLimitSeconds);
        } finally {
            unlink($cache);
            rmdir($dir);
        }
    }

    public function testWebFallsBackWhenNginxAndCacheMissing(): void
    {
        $budget = TimeBudget::detect('fpm-fcgi', 30, ['/no/such/nginx.conf'], '/no/such/cache.json');
        self::assertSame(60, $budget->hardLimitSeconds);
        self::assertSame(52, $budget->requestSeconds);
        self::assertSame(47, $budget->chatSeconds);
    }
}
