<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\FetchedPage;
use App\Support\HtmlToText;
use App\Support\MessageCompactor;
use PHPUnit\Framework\TestCase;

final class FetchAndCompactTest extends TestCase
{
    public function testCssUrlReturnsExcerptNotFullFile(): void
    {
        $css = str_repeat('body{color:navy}', 400);
        $out = FetchedPage::summarize(
            'https://example.com/media/templates/site/cassiopeia/css/template.min.css',
            'text/css',
            $css,
            new HtmlToText(),
        );
        self::assertSame('stylesheet', $out['kind']);
        self::assertLessThan(strlen($css), strlen($out['text']));
        self::assertStringContainsString('write_file', (string) ($out['note'] ?? ''));
    }

    public function testImageUrlTellsModelToUseFetchImage(): void
    {
        $out = FetchedPage::summarize(
            'https://example.com/images/logo.png',
            'image/png',
            "\x89PNG\r\n",
            new HtmlToText(),
        );
        self::assertSame('image', $out['kind']);
        self::assertStringContainsString('fetch_image', $out['text']);
    }

    public function testHtmlStillExtractsTitleAndText(): void
    {
        $out = FetchedPage::summarize(
            'https://example.com/about',
            'text/html',
            '<html><head><title>About</title></head><body><p>Hello there.</p></body></html>',
            new HtmlToText(),
        );
        self::assertSame('html', $out['kind']);
        self::assertSame('About', $out['title']);
        self::assertStringContainsString('Hello there', $out['text']);
    }

    public function testCompactorStubsOldToolResultsAndKeepsRecent(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'go'],
            ['role' => 'tool', 'tool_call_id' => 'a', 'content' => str_repeat('OLD', 100)],
            ['role' => 'tool', 'tool_call_id' => 'b', 'content' => '{"ok":true,"keep":"me"}'],
        ];
        $out = MessageCompactor::compact($messages, 1, 4000);
        self::assertStringContainsString('omitted', $out[2]['content']);
        self::assertStringContainsString('Do not call that tool again', $out[2]['content']);
        self::assertSame('{"ok":true,"keep":"me"}', $out[3]['content']);
        self::assertSame('go', $out[1]['content']);
    }

    public function testCompactorStubsOldMultimodalToolResults(): void
    {
        $messages = [
            ['role' => 'tool', 'tool_call_id' => 'old', 'content' => [
                ['type' => 'text', 'text' => 'old shot'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,xxx']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'new', 'content' => [
                ['type' => 'text', 'text' => '{"ok":true}'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,yyy']],
            ]],
        ];
        $out = MessageCompactor::compact($messages, 1, 4000);
        self::assertIsString($out[0]['content']);
        self::assertStringContainsString('omitted', $out[0]['content']);
        self::assertIsArray($out[1]['content']);
        self::assertSame('{"ok":true}', $out[1]['content'][0]['text'] ?? null);
    }
}
