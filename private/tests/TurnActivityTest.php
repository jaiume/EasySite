<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\TurnActivity;
use PHPUnit\Framework\TestCase;

final class TurnActivityTest extends TestCase
{
    public function testToolLineIncludesPathAndStripsContent(): void
    {
        $line = TurnActivity::toolLine('write_file', [
            'path' => 'css/site.css',
            'content' => 'SECRET',
        ]);
        self::assertSame('Using write_file — css/site.css', $line);
        self::assertStringNotContainsString('SECRET', $line);
    }

    public function testToolLineForEditFileShowsOldNotNew(): void
    {
        $line = TurnActivity::toolLine('edit_file', [
            'path' => 'css/site.css',
            'old' => '#0d6b4c',
            'new' => 'SECRET',
        ]);
        self::assertSame('Using edit_file — css/site.css → #0d6b4c', $line);
        self::assertStringNotContainsString('SECRET', $line);
    }

    public function testToolLineJoinsRenamePaths(): void
    {
        self::assertSame(
            'Using rename — old.html → new.html',
            TurnActivity::toolLine('rename', ['from' => 'old.html', 'to' => 'new.html']),
        );
    }

    public function testFromModelMessagesSkipsUnansweredCallsAndFinalReply(): void
    {
        $activity = TurnActivity::fromModelMessages([
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'match the style'],
            [
                'role' => 'assistant',
                'content' => 'I will inspect the live CSS.',
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'function' => [
                            'name' => 'fetch_page',
                            'arguments' => '{"url":"https://example.com/css/site.css"}',
                        ],
                    ],
                    [
                        'id' => 'call_2',
                        'function' => [
                            'name' => 'write_file',
                            'arguments' => '{"path":"css/site.css"}',
                        ],
                    ],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"success":true}'],
            ['role' => 'assistant', 'content' => 'Done matching the style.'],
        ]);
        self::assertCount(2, $activity);
        self::assertSame('status', $activity[0]['kind']);
        self::assertSame('I will inspect the live CSS.', $activity[0]['text']);
        self::assertSame('tool', $activity[1]['kind']);
        self::assertSame('Using fetch_page — https://example.com/css/site.css', $activity[1]['text']);
        self::assertSame('fetch_page', $activity[1]['name']);
    }

    public function testNormalizeDropsSliceYieldNotes(): void
    {
        $activity = TurnActivity::normalize([
            ['kind' => 'tool', 'text' => 'Using list_site — https://example.com'],
            ['kind' => 'status', 'text' => 'Paused. Press Continue to keep going.'],
            ['kind' => 'tool', 'text' => 'Using read_file — css/site.css'],
        ]);
        self::assertCount(2, $activity);
        self::assertSame('Using list_site — https://example.com', $activity[0]['text']);
        self::assertSame('Using read_file — css/site.css', $activity[1]['text']);
    }

    public function testNormalizeDropsWaitingAndPausedNotes(): void
    {
        $activity = TurnActivity::normalize([
            ['kind' => 'status', 'text' => 'Waiting for the model…'],
            ['kind' => 'status', 'text' => 'I will rewrite the CSS.'],
            ['kind' => 'status', 'text' => 'Paused: The model did not respond in time.'],
            ['kind' => 'status', 'text' => 'Using search — css/site.css'],
        ]);
        self::assertCount(2, $activity);
        self::assertSame('I will rewrite the CSS.', $activity[0]['text']);
        self::assertSame('tool', $activity[1]['kind']);
        self::assertSame('Using search — css/site.css', $activity[1]['text']);
    }
}
