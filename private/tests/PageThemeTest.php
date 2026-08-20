<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\HtmlToText;
use App\Support\PageTheme;
use PHPUnit\Framework\TestCase;

final class PageThemeTest extends TestCase
{
    public function testExtractsColoursFontsAndRegions(): void
    {
        $html = <<<HTML
<!doctype html>
<html>
<head>
  <title>TashInc</title>
  <link rel="stylesheet" href="/css/site.css">
  <style>h1{font-family:"Source Sans 3", sans-serif;color:#1a1a2e}</style>
</head>
<body>
<header class="site-header">
  <a href="/">Home</a>
  <a href="/about">About</a>
  <img src="/images/logo.png" alt="TashInc">
</header>
<main>Hello</main>
<footer class="site-footer"><a href="mailto:hi@example.com">Email</a></footer>
</body>
</html>
HTML;
        $css = ':root{--accent:#c2185b;--ink:#1a1a2e} body{background:#f7f3ea;color:rgb(26,26,46)}';
        $theme = PageTheme::fromHtml($html, 'https://example.com/', [$css]);
        self::assertSame('TashInc', $theme['title']);
        self::assertContains('#c2185b', $theme['colours']);
        self::assertContains('#1a1a2e', $theme['colours']);
        self::assertContains('#f7f3ea', $theme['colours']);
        self::assertNotSame([], $theme['fonts']);
        self::assertSame('header', $theme['header']['tag'] ?? null);
        self::assertSame('Home', $theme['header']['links'][0]['text'] ?? null);
        self::assertSame('logo.png', $theme['header']['images'][0]['src'] ?? null);
        self::assertSame('footer', $theme['footer']['tag'] ?? null);
        self::assertStringContainsString('edit_file', (string) $theme['note']);
    }

    public function testStylesheetUrlsFromLinkTags(): void
    {
        $html = '<link rel="stylesheet" href="/media/user.css"><link rel="icon" href="/favicon.ico">';
        $urls = (new HtmlToText())->stylesheetUrls($html, 'https://example.com/index.php');
        self::assertSame(['https://example.com/media/user.css'], $urls);
    }
}
