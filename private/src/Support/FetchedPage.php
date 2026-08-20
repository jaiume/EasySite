<?php

declare(strict_types=1);

namespace App\Support;

final class FetchedPage
{
    /**
     * @return array{kind: string, title: string, text: string, note?: string}
     */
    public static function summarize(string $url, string $contentType, string $body, HtmlToText $html, int $maxHtmlChars = 20000): array
    {
        $ct = strtolower(trim(explode(';', $contentType, 2)[0]));
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));

        if (self::isImage($ct, $path, $body)) {
            return [
                'kind' => 'image',
                'title' => '',
                'text' => 'This URL is an image, not a web page. Use fetch_image to save it into staging/images/.',
            ];
        }
        if (self::isCss($ct, $path)) {
            return [
                'kind' => 'stylesheet',
                'title' => basename($path) ?: 'stylesheet',
                'text' => self::excerpt($body, 2500),
                'note' => 'Vendor CSS excerpt only. Recreate the look in css/site.css with write_file. Do not copy Joomla or Cassiopeia files into the draft.',
            ];
        }
        if (self::isJs($ct, $path)) {
            return [
                'kind' => 'script',
                'title' => basename($path) ?: 'script',
                'text' => self::excerpt($body, 1500),
                'note' => 'Vendor JavaScript excerpt only. Do not copy it into the draft unless the owner asked for that behaviour.',
            ];
        }
        $extracted = $html->extract($body, $maxHtmlChars);

        return [
            'kind' => 'html',
            'title' => $extracted['title'],
            'text' => $extracted['text'],
        ];
    }

    private static function isCss(string $contentType, string $path): bool
    {
        return $contentType === 'text/css' || str_ends_with($path, '.css');
    }

    private static function isJs(string $contentType, string $path): bool
    {
        return $contentType === 'text/javascript'
            || $contentType === 'application/javascript'
            || $contentType === 'application/x-javascript'
            || str_ends_with($path, '.js');
    }

    private static function isImage(string $contentType, string $path, string $body): bool
    {
        if (str_starts_with($contentType, 'image/')) {
            return true;
        }
        if (preg_match('/\.(png|jpe?g|gif|webp|svg|ico)$/', $path) === 1) {
            return true;
        }
        if ($body === '') {
            return false;
        }
        $prefix = substr($body, 0, 8);

        return str_starts_with($prefix, "\x89PNG")
            || str_starts_with($prefix, 'GIF87a')
            || str_starts_with($prefix, 'GIF89a')
            || str_starts_with($prefix, "\xff\xd8\xff");
    }

    private static function excerpt(string $body, int $maxChars): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }
        if (mb_strlen($body) <= $maxChars) {
            return $body;
        }

        return mb_substr($body, 0, $maxChars) . '…';
    }
}
