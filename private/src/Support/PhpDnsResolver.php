<?php

declare(strict_types=1);

namespace App\Support;

final class PhpDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        $fallback = @gethostbyname($host);
        if (is_string($fallback) && $fallback !== $host && filter_var($fallback, FILTER_VALIDATE_IP)) {
            $ips[] = $fallback;
        }

        return array_values(array_unique($ips));
    }
}
