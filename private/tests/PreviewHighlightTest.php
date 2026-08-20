<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\PreviewHighlight;
use PHPUnit\Framework\TestCase;

final class PreviewHighlightTest extends TestCase
{
    public function testFromRequestRequiresBoxSize(): void
    {
        self::assertNull(PreviewHighlight::fromRequest(['x' => 10, 'y' => 10, 'width' => 0, 'height' => 40]));
        self::assertNull(PreviewHighlight::fromRequest('nope'));
    }

    public function testFromRequestSanitizesAndBuildsPrompt(): void
    {
        $highlight = PreviewHighlight::fromRequest([
            'path' => '/about.php',
            'viewport' => ['width' => 390, 'height' => 800],
            'scroll' => ['x' => 0, 'y' => 120],
            'box' => ['x' => 12.4, 'y' => 200, 'width' => 300, 'height' => 80],
            'elements' => [
                [
                    'tag' => 'H1',
                    'id' => 'hero',
                    'class' => 'hero-title',
                    'selector' => 'h1#hero.hero-title',
                    'text' => "  Welcome   to Tash  ",
                    'src' => '',
                    'href' => '',
                ],
                [
                    'tag' => 'img',
                    'selector' => 'img.photo',
                    'src' => 'images/hero.jpg',
                    'text' => '',
                ],
                [
                    'tag' => 'script',
                    'selector' => 'img.photo',
                    'text' => 'duplicate selector skipped',
                ],
                [
                    'tag' => 'a',
                    'selector' => 'a.evil{background:url(https://x)}',
                    'href' => 'javascript:alert(1)',
                    'text' => 'Click',
                ],
            ],
        ]);
        self::assertNotNull($highlight);
        self::assertSame('/about.php', $highlight['path']);
        self::assertSame(12, $highlight['box']['x']);
        self::assertCount(3, $highlight['elements']);
        self::assertSame('h1#hero.hero-title', $highlight['elements'][0]['selector']);
        self::assertSame('Welcome to Tash', $highlight['elements'][0]['text']);
        self::assertSame('images/hero.jpg', $highlight['elements'][1]['src']);
        self::assertSame('', $highlight['elements'][2]['href']);
        self::assertSame('', $highlight['elements'][2]['selector']);

        $prompt = PreviewHighlight::prompt($highlight);
        self::assertStringContainsString('drew a box on the preview', $prompt);
        self::assertStringContainsString('Box on /about.php: x=12, y=200, width=300, height=80', $prompt);
        self::assertStringContainsString('Viewport 390×800, scroll x=0 y=120', $prompt);
        self::assertStringContainsString('`h1#hero.hero-title` — "Welcome to Tash"', $prompt);
        self::assertStringContainsString('src images/hero.jpg', $prompt);
        self::assertStringNotContainsString('javascript', $prompt);
        self::assertStringNotContainsString('background:url', $prompt);
    }

    public function testRejectsTraversalPathAndAllowsBareBox(): void
    {
        $highlight = PreviewHighlight::fromRequest([
            'path' => '../etc/passwd',
            'left' => 1,
            'top' => 2,
            'width' => 10,
            'height' => 10,
        ]);
        self::assertNotNull($highlight);
        self::assertSame('', $highlight['path']);
        $prompt = PreviewHighlight::prompt($highlight);
        self::assertStringContainsString('current preview page', $prompt);
        self::assertStringContainsString('No distinct HTML elements', $prompt);
    }
}
