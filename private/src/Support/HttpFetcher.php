<?php

declare(strict_types=1);

namespace App\Support;

final class HttpFetcher
{
    public function __construct(
        private readonly UrlGuard $urlGuard,
        private readonly int $timeoutSeconds,
        private readonly int $maxRedirects,
        private readonly string $userAgent,
    ) {
    }

    /**
     * @return array{status: int, url: string, headers: array<string, string>, body: string}
     */
    public function get(string $url, int $maxBytes): array
    {
        $current = $url;
        for ($i = 0; $i <= $this->maxRedirects; $i++) {
            $this->urlGuard->assertSafeUrl($current);
            $result = $this->perform($current, $maxBytes);
            $status = $result['status'];
            if ($status >= 300 && $status < 400 && isset($result['headers']['location'])) {
                $next = $result['headers']['location'];
                if (str_starts_with($next, '/')) {
                    $parts = parse_url($current);
                    $next = 'https://' . ($parts['host'] ?? '') . $next;
                }
                $current = $next;
                continue;
            }

            return $result;
        }

        throw new UrlGuardException('Too many redirects.');
    }

    /**
     * @return array{status: int, url: string, headers: array<string, string>, body: string}
     */
    private function perform(string $url, int $maxBytes): array
    {
        $body = '';
        $headers = [];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new UrlGuardException('Unable to start HTTP request.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,image/*,*/*;q=0.8'],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$headers): int {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $len;
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $maxBytes): int {
                $body .= $chunk;
                if (strlen($body) > $maxBytes) {
                    return 0;
                }

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($ok === false && strlen($body) > $maxBytes) {
            throw new UrlGuardException('Response exceeded size cap.');
        }
        if ($ok === false && $err !== '') {
            throw new UrlGuardException('HTTP request failed.');
        }

        return [
            'status' => $status,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];
    }
}
