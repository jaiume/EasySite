<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\FetchedPage;
use App\Support\HtmlToText;
use App\Support\HttpFetcher;
use App\Support\ImageWriter;
use App\Support\PageTheme;
use App\Support\PathGuardException;
use App\Support\ServiceResult;
use App\Support\UrlGuard;
use App\Support\UrlGuardException;

final class HttpToolService
{
    public function __construct(
        private readonly UrlGuard $urlGuard,
        private readonly HttpFetcher $fetcher,
        private readonly HtmlToText $html,
        private readonly ImageWriter $images,
        private readonly Config $config,
        private readonly ToolLogger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function fetchPage(array $args): array
    {
        try {
            $url = (string) ($args['url'] ?? '');
            $this->urlGuard->assertSafeUrl($url);
            $max = $this->config->int('security.html_max_bytes', 524288);
            $result = $this->fetcher->get($url, $max);
            $extracted = FetchedPage::summarize(
                $result['url'],
                $result['headers']['content-type'] ?? '',
                $result['body'],
                $this->html,
            );
            $this->logger->log('fetch_page', null, strlen($result['body']), 0.0, $result['url']);
            $data = [
                'status' => $result['status'],
                'url' => $result['url'],
                'kind' => $extracted['kind'],
                'title' => $extracted['title'],
                'text' => $extracted['text'],
            ];
            if (isset($extracted['note'])) {
                $data['note'] = $extracted['note'];
            }

            return ServiceResult::ok('Fetched page', $data);
        } catch (UrlGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'URL_DENIED');
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function listSite(array $args): array
    {
        try {
            $start = (string) ($args['url'] ?? '');
            $depth = (int) ($args['depth'] ?? $this->config->int('security.crawl_default_depth', 2));
            $maxDepth = $this->config->int('security.crawl_max_depth', 4);
            if ($depth < 1) {
                $depth = 1;
            }
            if ($depth > $maxDepth) {
                $depth = $maxDepth;
            }
            $parsed = $this->urlGuard->assertSafeUrl($start);
            $host = $parsed['host'];
            $prefix = rtrim($parsed['path'], '/') ?: '/';
            $maxPages = $this->config->int('security.crawl_max_pages', 50);
            $delayMs = $this->config->int('security.crawl_delay_ms', 200);
            $htmlMax = $this->config->int('security.html_max_bytes', 524288);

            $queue = [['url' => $start, 'depth' => 0]];
            $seen = [];
            $nodes = [];
            while ($queue !== [] && count($nodes) < $maxPages) {
                $item = array_shift($queue);
                $url = $item['url'];
                $d = $item['depth'];
                $norm = $this->normalizeKey($url);
                if (isset($seen[$norm])) {
                    continue;
                }
                $seen[$norm] = true;
                try {
                    $safe = $this->urlGuard->assertSafeUrl($url);
                } catch (UrlGuardException) {
                    continue;
                }
                if ($safe['host'] !== $host) {
                    continue;
                }
                $path = $safe['path'];
                if ($path === '/cp' || str_starts_with($path, '/cp/')) {
                    continue;
                }
                if (!$this->underPrefix($path, $prefix)) {
                    continue;
                }
                $result = $this->fetcher->get($url, $htmlMax);
                $extracted = $this->html->extract($result['body'], 200);
                $nodes[] = [
                    'url' => $result['url'],
                    'path' => $path,
                    'status' => $result['status'],
                    'title' => $extracted['title'],
                    'depth' => $d,
                ];
                if ($d + 1 <= $depth) {
                    foreach ($this->html->hrefs($result['body'], $result['url']) as $href) {
                        $queue[] = ['url' => $href, 'depth' => $d + 1];
                    }
                }
                if ($delayMs > 0 && $queue !== []) {
                    usleep($delayMs * 1000);
                }
            }
            $this->logger->log('list_site', null, count($nodes), 0.0, $start);

            return ServiceResult::ok('Site structure', [
                'start' => $start,
                'nodes' => $nodes,
                'truncated' => count($nodes) >= $maxPages,
            ]);
        } catch (UrlGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'URL_DENIED');
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function fetchImage(array $args): array
    {
        try {
            $url = (string) ($args['url'] ?? '');
            $this->urlGuard->assertSafeUrl($url);
            $max = $this->config->int('security.image_max_bytes', 5242880);
            $result = $this->fetcher->get($url, $max);
            $ctype = strtolower($result['headers']['content-type'] ?? '');
            $ctype = trim(explode(';', $ctype)[0]);
            if ($ctype !== '' && !str_starts_with($ctype, 'image/')) {
                return ServiceResult::fail('URL did not return an image.', 'NOT_IMAGE');
            }
            $name = $args['filename'] ?? basename(parse_url($url, PHP_URL_PATH) ?: 'image');
            $saved = $this->images->save($result['body'], is_string($name) ? $name : 'image');
            $this->logger->log('fetch_image', $saved['path'], $saved['bytes'], 0.0, $url);

            return ServiceResult::ok('Saved image', $saved);
        } catch (UrlGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'URL_DENIED');
        } catch (PathGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'PATH_DENIED');
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function inspectPage(array $args): array
    {
        try {
            $url = (string) ($args['url'] ?? '');
            $this->urlGuard->assertSafeUrl($url);
            $max = $this->config->int('security.html_max_bytes', 524288);
            $result = $this->fetcher->get($url, $max);
            $ctype = strtolower(trim(explode(';', $result['headers']['content-type'] ?? '')[0]));
            $path = strtolower((string) (parse_url($result['url'], PHP_URL_PATH) ?: ''));
            $host = strtolower((string) (parse_url($result['url'], PHP_URL_HOST) ?: ''));
            if ($ctype === 'text/css' || str_ends_with($path, '.css')) {
                $theme = PageTheme::fromCss($result['body'], $result['url']);
            } else {
                $sheets = [];
                $fetched = 0;
                foreach ($this->html->stylesheetUrls($result['body'], $result['url']) as $cssUrl) {
                    if ($fetched >= 4) {
                        break;
                    }
                    try {
                        $safe = $this->urlGuard->assertSafeUrl($cssUrl);
                    } catch (UrlGuardException) {
                        continue;
                    }
                    $cssHost = $safe['host'];
                    $same = $cssHost === $host
                        || $cssHost === 'fonts.googleapis.com'
                        || str_ends_with($cssHost, '.googleapis.com');
                    if (!$same) {
                        continue;
                    }
                    $css = $this->fetcher->get($cssUrl, $max);
                    $sheets[] = $css['body'];
                    $fetched++;
                }
                $theme = PageTheme::fromHtml($result['body'], $result['url'], $sheets);
            }
            $this->logger->log('inspect_page', null, strlen($result['body']), 0.0, $result['url']);

            return ServiceResult::ok('Page look', $theme);
        } catch (UrlGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'URL_DENIED');
        }
    }

    private function underPrefix(string $path, string $prefix): bool
    {
        if ($prefix === '/') {
            return true;
        }

        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    private function normalizeKey(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = $parts['path'] ?? '/';

        return $host . $path;
    }
}
