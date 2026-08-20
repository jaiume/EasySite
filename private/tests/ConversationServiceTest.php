<?php

declare(strict_types=1);

namespace App\Tests;

use App\DAO\ChatStore;
use App\DAO\PendingTurnStore;
use App\Services\CheckpointService;
use App\Services\ConversationService;
use App\Support\Config;
use PHPUnit\Framework\TestCase;

final class ConversationServiceTest extends TestCase
{
    private string $root;
    private CheckpointService $checkpoints;
    private ChatStore $chats;
    private PendingTurnStore $pending;
    private ConversationService $conversation;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cp-chk-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public_html/staging/images', 0777, true);
        mkdir($this->root . '/private/var/data', 0777, true);
        mkdir($this->root . '/private/config', 0777, true);
        file_put_contents($this->root . '/public_html/staging/index.php', 'DRAFT ONE');
        $ini = <<<INI
[app]
name = test
[paths]
docroot = "{$this->root}/public_html"
staging_root = "{$this->root}/public_html/staging"
live_root = "{$this->root}/public_html"
INI;
        $iniPath = $this->root . '/private/config/config.ini';
        file_put_contents($iniPath, $ini);
        $config = new Config($iniPath, $this->root . '/private');
        $this->checkpoints = new CheckpointService($config);
        $this->chats = new ChatStore($this->root . '/private/var/data/chats/current.json');
        $this->pending = new PendingTurnStore($this->root . '/private/var/data/chats/pending.json');
        $this->conversation = new ConversationService($this->chats, $this->checkpoints, $this->pending);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testRestoreRevertsStagingAndTruncatesChat(): void
    {
        $first = $this->checkpoints->create();
        file_put_contents($this->root . '/public_html/staging/index.php', 'DRAFT TWO');
        file_put_contents($this->root . '/public_html/staging/extra.php', 'EXTRA');
        $second = $this->checkpoints->create();
        file_put_contents($this->root . '/public_html/staging/index.php', 'DRAFT THREE');

        $this->chats->save([
            ['role' => 'user', 'content' => 'first', 'id' => 'aa', 'checkpoint_id' => $first],
            ['role' => 'assistant', 'content' => 'did first'],
            ['role' => 'user', 'content' => 'second', 'id' => 'bb', 'checkpoint_id' => $second],
            ['role' => 'assistant', 'content' => 'did second'],
        ]);

        $result = $this->conversation->restoreToMessage('aa');
        self::assertTrue($result['success'], $result['message']);
        self::assertSame('DRAFT ONE', (string) file_get_contents($this->root . '/public_html/staging/index.php'));
        self::assertFileDoesNotExist($this->root . '/public_html/staging/extra.php');
        self::assertSame([], $this->chats->load());
        self::assertSame('first', $result['data']['composer'] ?? '');
        self::assertDirectoryDoesNotExist($this->root . '/private/var/data/checkpoints/' . $first);
        self::assertDirectoryDoesNotExist($this->root . '/private/var/data/checkpoints/' . $second);
    }

    public function testRestoreToLaterMessageKeepsEarlierTurn(): void
    {
        $first = $this->checkpoints->create();
        file_put_contents($this->root . '/public_html/staging/index.php', 'DRAFT TWO');
        $second = $this->checkpoints->create();
        file_put_contents($this->root . '/public_html/staging/index.php', 'DRAFT THREE');

        $this->chats->save([
            ['role' => 'user', 'content' => 'first', 'id' => 'aa', 'checkpoint_id' => $first],
            ['role' => 'assistant', 'content' => 'did first'],
            ['role' => 'user', 'content' => 'second', 'id' => 'bb', 'checkpoint_id' => $second],
            ['role' => 'assistant', 'content' => 'did second'],
        ]);

        $result = $this->conversation->restoreToMessage('bb');
        self::assertTrue($result['success'], $result['message']);
        self::assertSame('DRAFT TWO', (string) file_get_contents($this->root . '/public_html/staging/index.php'));
        $kept = $this->chats->load();
        self::assertCount(2, $kept);
        self::assertSame('first', $kept[0]['content']);
        self::assertSame('second', $result['data']['composer'] ?? '');
        self::assertDirectoryExists($this->root . '/private/var/data/checkpoints/' . $first);
        self::assertDirectoryDoesNotExist($this->root . '/private/var/data/checkpoints/' . $second);
    }

    public function testClearWipesChatAndCheckpoints(): void
    {
        $id = $this->checkpoints->create();
        $this->chats->save([
            ['role' => 'user', 'content' => 'hello', 'id' => 'aa', 'checkpoint_id' => $id],
        ]);
        $this->pending->claim('run1', ['messages' => [['role' => 'user', 'content' => 'hello']]]);
        $this->conversation->clear();
        self::assertSame([], $this->chats->load());
        self::assertDirectoryDoesNotExist($this->root . '/private/var/data/checkpoints/' . $id);
        self::assertFalse($this->pending->exists());
    }

    public function testCanContinueWhenLastMessageIsUser(): void
    {
        self::assertFalse($this->conversation->canContinue());
        $this->chats->save([
            ['role' => 'user', 'content' => 'hello', 'id' => 'aa'],
        ]);
        self::assertTrue($this->conversation->canContinue());
    }

    public function testRestoreClearsPendingTurn(): void
    {
        $id = $this->checkpoints->create();
        $this->chats->save([
            ['role' => 'user', 'content' => 'hello', 'id' => 'aa', 'checkpoint_id' => $id],
        ]);
        $this->pending->claim('run1', ['messages' => [['role' => 'user', 'content' => 'hello']]]);
        $this->conversation->restoreToMessage('aa');
        self::assertFalse($this->pending->exists());
    }

    public function testMissingMessageFails(): void
    {
        $result = $this->conversation->restoreToMessage('nope');
        self::assertFalse($result['success']);
        self::assertSame('NOT_FOUND', $result['error']['code'] ?? '');
    }

    public function testDisplayMessagesIncludesStoredActivity(): void
    {
        $this->chats->save([
            [
                'role' => 'user',
                'content' => 'match the style',
                'id' => 'aa',
                'activity' => [
                    ['kind' => 'tool', 'text' => 'Using list_site — https://example.com', 'name' => 'list_site'],
                ],
            ],
            ['role' => 'assistant', 'content' => 'done'],
        ]);
        $out = $this->conversation->displayMessages();
        self::assertCount(2, $out);
        self::assertSame('user', $out[0]['role']);
        self::assertSame(
            [['kind' => 'tool', 'text' => 'Using list_site — https://example.com', 'name' => 'list_site']],
            $out[0]['activity'],
        );
        self::assertArrayNotHasKey('activity', $out[1]);
    }

    public function testDisplayMessagesDerivesActivityFromPendingTurn(): void
    {
        $this->chats->save([
            ['role' => 'user', 'content' => 'match the style', 'id' => 'aa'],
        ]);
        $this->pending->claim('run1', [
            'messages' => [
                ['role' => 'user', 'content' => 'match the style'],
                [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [
                        [
                            'id' => 'call_1',
                            'function' => [
                                'name' => 'fetch_page',
                                'arguments' => '{"url":"https://example.com"}',
                            ],
                        ],
                    ],
                ],
                ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{}'],
            ],
        ]);
        $out = $this->conversation->displayMessages();
        self::assertCount(1, $out);
        self::assertSame('Using fetch_page — https://example.com', $out[0]['activity'][0]['text'] ?? '');
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
