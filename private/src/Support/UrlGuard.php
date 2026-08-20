<?php

declare(strict_types=1);

namespace App\Support;

final class UrlGuard
{
    public function __construct(
        private readonly DnsResolver $dns,
    ) {
    }

    /**
     * @return array{url: string, host: string, path: string}
     */
    public function assertSafeUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '' || str_contains($url, "\0")) {
            throw new UrlGuardException('Invalid URL.');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new UrlGuardException('Invalid URL.');
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw new UrlGuardException('Only https URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UrlGuardException('URLs with userinfo are not allowed.');
        }

        $host = strtolower((string) $parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            throw new UrlGuardException('Host is not allowed.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) && $this->isBlockedIp($host)) {
            throw new UrlGuardException('IP address is not allowed.');
        }

        $ips = $this->dns->resolve($host);
        if ($ips === []) {
            throw new UrlGuardException('Host could not be resolved.');
        }
        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new UrlGuardException('Host resolves to a private or reserved address.');
            }
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }

        return [
            'url' => $url,
            'host' => $host,
            'path' => $path,
        ];
    }

    public function isBlockedIp(string $ip): bool
    {
        $ip = strtolower(trim($ip));
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }
            $ranges = [
                ['0.0.0.0', '0.255.255.255'],
                ['10.0.0.0', '10.255.255.255'],
                ['100.64.0.0', '100.127.255.255'],
                ['127.0.0.0', '127.255.255.255'],
                ['169.254.0.0', '169.254.255.255'],
                ['172.16.0.0', '172.31.255.255'],
                ['192.0.0.0', '192.0.0.255'],
                ['192.168.0.0', '192.168.255.255'],
                ['198.18.0.0', '198.19.255.255'],
                ['224.0.0.0', '255.255.255.255'],
            ];
            foreach ($ranges as [$start, $end]) {
                $s = ip2long($start);
                $e = ip2long($end);
                if ($s !== false && $e !== false && $long >= $s && $long <= $e) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if ($packed === false) {
                return true;
            }
            $blocked = [
                '::1',
                '::',
                'fd00:ec2::254',
            ];
            foreach ($blocked as $b) {
                $bp = @inet_pton($b);
                if ($bp !== false && $bp === $packed) {
                    return true;
                }
            }
            // ULA fc00::/7, link-local fe80::/10, multicast ff00::/8, loopback
            $ord0 = ord($packed[0]);
            $ord1 = ord($packed[1]);
            if ($ord0 === 0xff) {
                return true;
            }
            if ($ord0 === 0xfe && ($ord1 & 0xc0) === 0x80) {
                return true;
            }
            if (($ord0 & 0xfe) === 0xfc) {
                return true;
            }
            if ($packed === str_repeat("\0", 15) . "\x01") {
                return true;
            }

            return false;
        }

        return true;
    }
}
