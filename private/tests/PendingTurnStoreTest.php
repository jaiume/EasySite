<?php

declare(strict_types=1);

namespace App\Tests;

use App\DAO\PendingTurnStore;
use PHPUnit\Framework\TestCase;

final class PendingTurnStoreTest extends TestCase
{
    private string $dir;
    private PendingTurnStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cp-pending-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->store = new PendingTurnStore($this->dir . '/pending.json');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testSaveAndLoad(): void
    {
        $this->store->claim('aaa', [
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'round' => 2,
        ]);
        $loaded = $this->store->load();
        self::assertNotNull($loaded);
        self::assertSame('aaa', $loaded['run_id'] ?? null);
        self::assertSame(2, $loaded['round'] ?? null);
        self::assertTrue($this->store->exists());
    }

    public function testSaveIfCurrentIgnoresOtherRun(): void
    {
        $this->store->claim('aaa', [
            'messages' => [['role' => 'user', 'content' => 'one']],
        ]);
        $this->store->saveIfCurrent('bbb', [
            'messages' => [['role' => 'user', 'content' => 'two']],
        ]);
        $loaded = $this->store->load();
        self::assertSame('aaa', $loaded['run_id'] ?? null);
        self::assertSame('one', $loaded['messages'][0]['content'] ?? null);
    }

    public function testClaimTakesOver(): void
    {
        $this->store->claim('aaa', [
            'messages' => [['role' => 'user', 'content' => 'one']],
        ]);
        $this->store->claim('bbb', [
            'messages' => [['role' => 'user', 'content' => 'two']],
        ]);
        $loaded = $this->store->load();
        self::assertSame('bbb', $loaded['run_id'] ?? null);
        self::assertSame('two', $loaded['messages'][0]['content'] ?? null);
    }

    public function testClearIfRunOnlyOwn(): void
    {
        $this->store->claim('aaa', [
            'messages' => [['role' => 'user', 'content' => 'one']],
        ]);
        $this->store->clearIfRun('bbb');
        self::assertTrue($this->store->exists());
        $this->store->clearIfRun('aaa');
        self::assertFalse($this->store->exists());
    }

    public function testHaltAutoStopsWorkerButKeepsPending(): void
    {
        $this->store->claim('aaa', [
            'messages' => [['role' => 'user', 'content' => 'one']],
        ]);
        self::assertTrue($this->store->allowsAutoContinue());
        $this->store->haltAuto();
        self::assertTrue($this->store->exists());
        self::assertFalse($this->store->allowsAutoContinue());
        $this->store->saveIfCurrent('aaa', [
            'messages' => [['role' => 'user', 'content' => 'one']],
            'round' => 3,
        ]);
        self::assertFalse($this->store->allowsAutoContinue());
        $this->store->resumeAuto();
        self::assertTrue($this->store->allowsAutoContinue());
        self::assertTrue($this->store->exists());
    }
}
