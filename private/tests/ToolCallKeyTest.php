<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\ToolCallKey;
use PHPUnit\Framework\TestCase;

final class ToolCallKeyTest extends TestCase
{
    public function testSameSearchArgsShareFingerprint(): void
    {
        $a = ToolCallKey::fingerprint('search', ['path' => 'css/site.css', 'query' => 'accent']);
        $b = ToolCallKey::fingerprint('search', ['query' => 'accent', 'path' => 'css/site.css']);
        self::assertSame($a, $b);
    }

    public function testMissingSearchPathMatchesDot(): void
    {
        $a = ToolCallKey::fingerprint('search', ['query' => 'accent']);
        $b = ToolCallKey::fingerprint('search', ['query' => 'accent', 'path' => '.']);
        self::assertSame($a, $b);
    }

    public function testWriteFileSamePathDifferentContentIsDistinct(): void
    {
        $a = ToolCallKey::fingerprint('write_file', ['path' => 'css/site.css', 'content' => 'green']);
        $b = ToolCallKey::fingerprint('write_file', ['path' => 'css/site.css', 'content' => 'navy']);
        self::assertNotSame($a, $b);
    }

    public function testCompletedIgnoresCallsWithoutResults(): void
    {
        $done = ToolCallKey::completed([
            [
                'role' => 'assistant',
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'function' => [
                            'name' => 'search',
                            'arguments' => '{"path":"css/site.css","query":"accent"}',
                        ],
                    ],
                ],
            ],
        ]);
        self::assertSame([], $done);
    }

    public function testCompletedIncludesAnsweredCalls(): void
    {
        $args = '{"path":"css/site.css","query":"accent"}';
        $fp = ToolCallKey::fingerprint('search', json_decode($args, true));
        $done = ToolCallKey::completed([
            [
                'role' => 'assistant',
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'function' => [
                            'name' => 'search',
                            'arguments' => $args,
                        ],
                    ],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"success":true}'],
        ]);
        self::assertArrayHasKey($fp, $done);
    }

    public function testSearchBlockedAfterReadFile(): void
    {
        $messages = [
            [
                'role' => 'assistant',
                'tool_calls' => [
                    [
                        'id' => 'r1',
                        'function' => [
                            'name' => 'read_file',
                            'arguments' => '{"path":"css/site.css"}',
                        ],
                    ],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'r1', 'content' => '{"success":true}'],
        ];
        $usage = ToolCallKey::fileUsage($messages);
        $reason = ToolCallKey::searchBlockReason(['path' => 'css/site.css', 'query' => '.site-nav'], $usage);
        self::assertNotNull($reason);
        self::assertStringContainsString('edit_file', $reason);
    }

    public function testSecondSearchOnSamePathBlocked(): void
    {
        $messages = [
            [
                'role' => 'assistant',
                'tool_calls' => [
                    [
                        'id' => 's1',
                        'function' => [
                            'name' => 'search',
                            'arguments' => '{"path":"css/site.css","query":"site-nav"}',
                        ],
                    ],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 's1', 'content' => '{"success":true}'],
        ];
        $usage = ToolCallKey::fileUsage($messages);
        $reason = ToolCallKey::searchBlockReason(['path' => 'css/site.css', 'query' => '@media'], $usage);
        self::assertNotNull($reason);
        self::assertStringContainsString('already searched', $reason);
    }

    public function testSearchLimitOfTwo(): void
    {
        $usage = [
            'read' => [],
            'searched' => ['a.php' => true, 'b.php' => true],
            'searches' => 2,
        ];
        $reason = ToolCallKey::searchBlockReason(['path' => 'c.php', 'query' => 'x'], $usage);
        self::assertNotNull($reason);
        self::assertStringContainsString('Search limit', $reason);
    }

    public function testSkippedSearchDoesNotCountTowardLimit(): void
    {
        $messages = [
            [
                'role' => 'assistant',
                'tool_calls' => [
                    [
                        'id' => 's1',
                        'function' => [
                            'name' => 'search',
                            'arguments' => '{"path":"css/site.css","query":"nav"}',
                        ],
                    ],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 's1', 'content' => '{"success":true,"data":{"skipped":true}}'],
        ];
        $usage = ToolCallKey::fileUsage($messages);
        self::assertSame(0, $usage['searches']);
        self::assertNull(ToolCallKey::searchBlockReason(['path' => 'about.php', 'query' => 'h1'], $usage));
    }
}
