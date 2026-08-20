<?php

declare(strict_types=1);

namespace App\Support;

interface DnsResolver
{
    /**
     * @return list<string> IP addresses
     */
    public function resolve(string $host): array;
}
