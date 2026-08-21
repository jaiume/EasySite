<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\CssViewport;
use App\Support\HtmlToText;
use App\Support\PathGuard;
use App\Support\PathGuardException;
use App\Support\ServiceResult;

final class DraftViewService
{
    private const MIME = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'txt' => 'text/plain; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
    ];

    public function __construct(
        private readonly PathGuard $pathGuard,
        private readonly ToolLogger $logger,
        private readonly HtmlToText $htmlToText,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function http(string $path, array $query = []): array
    {
        try {
            $file = $this->resolveFile($path);
        } catch (PathGuardException $e) {
            return $this->httpError(404, 'Not found.');
        }
        if ($file === null) {
            return $this->httpError(404, 'Not found.');
        }
        $ext = strtolower(pathinfo($file['rel'], PATHINFO_EXTENSION));
        if ($ext === 'php') {
            try {
                $html = $this->renderPhp($file['real'], $file['rel'], $query);
            } catch (\Throwable $e) {
                return $this->httpError(500, 'That draft page failed to render.');
            }

            return [
                'status' => 200,
                'body' => $html,
                'headers' => [
                    'Content-Type' => 'text/html; charset=utf-8',
                    'Cache-Control' => 'private, no-store',
                    'X-Robots-Tag' => 'noindex, nofollow',
                ],
            ];
        }
        $bytes = (string) file_get_contents($file['real']);
        $mime = self::MIME[$ext] ?? 'application/octet-stream';

        return [
            'status' => 200,
            'body' => $bytes,
            'headers' => [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, max-age=0',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function view(array $args): array
    {
        $path = trim(str_replace('\\', '/', (string) ($args['path'] ?? 'index.php')));
        if (str_starts_with($path, '/staging/')) {
            $path = substr($path, strlen('/staging/'));
        }
        $path = ltrim($path, '/');
        if ($path === '' || $path === '.') {
            $path = 'index.php';
        }
        $width = CssViewport::width($args['size'] ?? 'desktop', $args['width'] ?? null);
        $height = CssViewport::height($args['height'] ?? null, $width);
        try {
            $file = $this->resolveFile($path);
        } catch (PathGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'PATH_DENIED');
        }
        if ($file === null) {
            return ServiceResult::fail('Path does not exist.', 'NOT_FOUND');
        }
        $ext = strtolower(pathinfo($file['rel'], PATHINFO_EXTENSION));
        if ($ext !== 'php' && $ext !== 'html' && $ext !== 'htm') {
            return ServiceResult::fail('view_draft is for a page (php/html), not ' . $ext . '. Use read_file or inspect_draft.', 'TYPE_DENIED');
        }
        try {
            $html = $ext === 'php'
                ? $this->renderPhp($file['real'], $file['rel'], [])
                : (string) file_get_contents($file['real']);
        } catch (\Throwable $e) {
            return ServiceResult::fail('That draft page failed to render.', 'RENDER_FAILED');
        }
        $css = '';
        try {
            $cssFile = $this->pathGuard->resolveExisting('css/site.css');
            if (is_file($cssFile)) {
                $css = (string) file_get_contents($cssFile);
            }
        } catch (PathGuardException $e) {
            $css = '';
        }
        $extracted = $this->htmlToText->extract($html, 4000);
        $data = [
            'path' => $file['rel'],
            'viewport' => ['width' => $width, 'height' => $height],
            'title' => $extracted['title'] !== '' ? $extracted['title'] : $this->htmlTitle($html),
            'headings' => $this->headings($html),
            'images' => $this->images($html),
            'media' => CssViewport::matchingMedia($css, $width, $height),
            'text' => $extracted['text'],
            'note' => 'Rendered draft HTML at this viewport (not the squeezed editor iframe). Media lists CSS @media rules that apply at this width. Use this after layout edits.',
        ];
        $shot = $this->screenshotJpeg($html, $file['rel'], $width, $height);
        if ($shot !== null) {
            $data['screenshot'] = true;
            $data['screenshot_jpeg'] = base64_encode($shot);
            $data['note'] .= ' A screenshot of this viewport is attached.';
        } else {
            $data['screenshot'] = false;
            $data['note'] .= ' No headless browser on this server, so there is no screenshot — use the outline and matching media rules.';
        }
        $this->logger->log('view_draft', $file['rel'], strlen($html));

        return ServiceResult::ok('Rendered draft at ' . $width . '×' . $height, $data);
    }

    /**
     * @return array{real: string, rel: string}|null
     */
    public function resolveFile(string $userPath): ?array
    {
        $userPath = trim(str_replace('\\', '/', $userPath));
        $userPath = ltrim($userPath, '/');
        if ($userPath === '') {
            $userPath = '.';
        }
        $real = $this->pathGuard->resolveExisting($userPath);
        if (is_dir($real)) {
            $index = 'index.php';
            if ($userPath !== '.' && $userPath !== '') {
                $index = rtrim($userPath, '/') . '/index.php';
            }
            $real = $this->pathGuard->resolveExisting($index);
        }
        if (!is_file($real)) {
            return null;
        }
        $root = $this->pathGuard->stagingRoot();
        $rel = ltrim(substr($real, strlen($root)), '/');

        return ['real' => $real, 'rel' => $rel];
    }

    /**
     * @param array<string, mixed> $query
     */
    public function renderPhp(string $absFile, string $rel, array $query): string
    {
        $prevGet = $_GET;
        $prevScript = $_SERVER['SCRIPT_NAME'] ?? null;
        $prevSelf = $_SERVER['PHP_SELF'] ?? null;
        $cwd = getcwd();
        $_GET = [];
        foreach ($query as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $_GET[$key] = (string) $value;
            }
        }
        $script = '/cp/preview/' . ltrim($rel, '/');
        $_SERVER['SCRIPT_NAME'] = $script;
        $_SERVER['PHP_SELF'] = $script;
        $root = $this->pathGuard->stagingRoot();
        if (is_dir($root)) {
            chdir($root);
        }
        try {
            return (static function (string $file, string $BASE): string {
                ob_start();
                include $file;

                return (string) ob_get_clean();
            })($absFile, '/cp/preview/');
        } finally {
            $_GET = $prevGet;
            if ($prevScript === null) {
                unset($_SERVER['SCRIPT_NAME']);
            } else {
                $_SERVER['SCRIPT_NAME'] = $prevScript;
            }
            if ($prevSelf === null) {
                unset($_SERVER['PHP_SELF']);
            } else {
                $_SERVER['PHP_SELF'] = $prevSelf;
            }
            if (is_string($cwd)) {
                chdir($cwd);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function headings(string $html): array
    {
        $out = [];
        if (preg_match_all('/<h([1-3])\b[^>]*>(.*?)<\/h\1>/is', $html, $m) < 1) {
            return [];
        }
        foreach ($m[1] as $i => $level) {
            $text = trim(html_entity_decode(strip_tags((string) $m[2][$i]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
            if ($text === '') {
                continue;
            }
            $out[] = 'h' . $level . ' ' . $text;
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array{src: string, alt: string}>
     */
    private function images(string $html): array
    {
        $out = [];
        if (preg_match_all('/<img\b[^>]*>/i', $html, $tags) < 1) {
            return [];
        }
        foreach ($tags[0] as $tag) {
            $src = '';
            $alt = '';
            if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', (string) $tag, $sm) === 1) {
                $src = (string) $sm[1];
            }
            if (preg_match('/\balt\s*=\s*["\']([^"\']*)["\']/i', (string) $tag, $am) === 1) {
                $alt = (string) $am[1];
            }
            if ($src === '') {
                continue;
            }
            $out[] = ['src' => $src, 'alt' => $alt];
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    private function htmlTitle(string $html): string
    {
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $m) !== 1) {
            return '';
        }

        return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function screenshotJpeg(string $html, string $rel, int $width, int $height): ?string
    {
        $chrome = $this->chromeBin();
        if ($chrome === null) {
            return null;
        }
        $dir = sys_get_temp_dir() . '/cp-view-' . bin2hex(random_bytes(4));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }
        $htmlFile = $dir . '/page.html';
        $pngFile = $dir . '/shot.png';
        $root = $this->pathGuard->stagingRoot();
        $base = 'file://' . $root . '/';
        $doc = $this->htmlForFileScreenshot($html, $base);
        file_put_contents($htmlFile, $doc);
        $cmd = [
            $chrome,
            '--headless=new',
            '--disable-gpu',
            '--hide-scrollbars',
            '--no-sandbox',
            '--window-size=' . $width . ',' . $height,
            '--screenshot=' . $pngFile,
            $htmlFile,
        ];
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $desc, $pipes, $dir, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            $this->rmDir($dir);

            return null;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $png = is_file($pngFile) ? (string) file_get_contents($pngFile) : '';
        $this->rmDir($dir);
        if ($png === '' || !str_starts_with($png, "\x89PNG")) {
            return null;
        }

        return $this->pngToJpeg($png, $width);
    }

    private function htmlForFileScreenshot(string $html, string $baseHref): string
    {
        $html = preg_replace('#(?:/cp/preview/|/staging/)#', '', $html) ?? $html;
        if (!preg_match('/<base\b/i', $html)) {
            $html = preg_replace('/<head([^>]*)>/i', '<head$1><base href="' . htmlspecialchars($baseHref, ENT_QUOTES) . '">', $html, 1) ?? $html;
        }

        return $html;
    }

    private function pngToJpeg(string $png, int $maxWidth): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return $png;
        }
        $im = @imagecreatefromstring($png);
        if ($im === false) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w > $maxWidth && $w > 0) {
            $nw = $maxWidth;
            $nh = max(1, (int) round($h * ($maxWidth / $w)));
            $resized = imagecreatetruecolor($nw, $nh);
            if ($resized !== false) {
                imagecopyresampled($resized, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($im);
                $im = $resized;
            }
        }
        ob_start();
        imagejpeg($im, null, 72);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    private function chromeBin(): ?string
    {
        $env = getenv('CHROME_BIN');
        $candidates = [
            is_string($env) ? $env : '',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/snap/bin/chromium',
        ];
        foreach ($candidates as $bin) {
            if ($bin !== '' && is_executable($bin)) {
                return $bin;
            }
        }

        return null;
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function httpError(int $status, string $message): array
    {
        return [
            'status' => $status,
            'body' => $message,
            'headers' => [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'private, no-store',
            ],
        ];
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
