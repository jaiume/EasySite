<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\ChatStore;
use App\DAO\PendingTurnStore;
use App\Support\ServiceResult;
use App\Support\TurnActivity;

final class ConversationService
{
    public function __construct(
        private readonly ChatStore $chats,
        private readonly CheckpointService $checkpoints,
        private readonly PendingTurnStore $pending,
    ) {
    }

    /**
     * Restore staging and chat to a previous user message. Drops later turns and checkpoints.
     *
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function restoreToMessage(string $messageId): array
    {
        $messages = $this->chats->load();
        $index = null;
        $checkpointId = null;
        foreach ($messages as $i => $row) {
            if (($row['role'] ?? '') === 'user' && (string) ($row['id'] ?? '') === $messageId) {
                $index = $i;
                $checkpointId = (string) ($row['checkpoint_id'] ?? '');
                break;
            }
        }
        if ($index === null) {
            return ServiceResult::fail('That message was not found.', 'NOT_FOUND');
        }
        if ($checkpointId === '' || !$this->checkpoints->exists($checkpointId)) {
            return ServiceResult::fail('No draft snapshot for that message.', 'MISSING_CHECKPOINT');
        }
        $restored = $this->checkpoints->restore($checkpointId);
        if (!$restored['success']) {
            return $restored;
        }
        $removed = $messages[$index];
        $kept = array_slice($messages, 0, $index);
        $this->chats->save($kept);
        $this->pending->clear();
        $keepIds = [];
        foreach ($kept as $row) {
            if (isset($row['checkpoint_id']) && is_string($row['checkpoint_id'])) {
                $keepIds[] = $row['checkpoint_id'];
            }
        }
        $this->checkpoints->deleteUnused($keepIds);
        $composer = '';
        if (isset($removed['composer']) && is_string($removed['composer']) && trim($removed['composer']) !== '') {
            $composer = $removed['composer'];
        } else {
            $composer = (string) ($removed['content'] ?? '');
        }

        return ServiceResult::ok('Draft restored. The message is back in the input.', [
            'message_id' => $messageId,
            'composer' => $composer,
        ]);
    }

    public function clear(): void
    {
        $this->chats->clear();
        $this->pending->clear();
        $this->checkpoints->deleteAll();
    }

    public function canContinue(): bool
    {
        if ($this->pending->exists()) {
            return true;
        }
        $messages = $this->chats->load();
        if ($messages === []) {
            return false;
        }
        $last = $messages[array_key_last($messages)];

        return ($last['role'] ?? '') === 'user';
    }

    /**
     * @return list<array{id?: string, role: string, content: string, activity?: list<array{kind: string, text: string, name?: string}>}>
     */
    public function displayMessages(): array
    {
        $messages = $this->chats->load();
        $pending = $this->pending->load();
        $lastIndex = $messages === [] ? null : array_key_last($messages);
        $incomplete = $lastIndex !== null && ($messages[$lastIndex]['role'] ?? '') === 'user';
        $lastUserIndex = null;
        foreach ($messages as $i => $row) {
            if (($row['role'] ?? '') === 'user') {
                $lastUserIndex = $i;
            }
        }
        $out = [];
        foreach ($messages as $i => $row) {
            $role = (string) ($row['role'] ?? '');
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            $item = [
                'role' => $role,
                'content' => (string) ($row['content'] ?? ''),
            ];
            if ($role === 'user') {
                if (isset($row['id']) && is_string($row['id'])) {
                    $item['id'] = $row['id'];
                }
                $activity = TurnActivity::normalize($row['activity'] ?? []);
                if (
                    $activity === []
                    && $incomplete
                    && $i === $lastUserIndex
                    && is_array($pending)
                    && isset($pending['messages'])
                    && is_array($pending['messages'])
                ) {
                    $activity = TurnActivity::fromModelMessages($pending['messages']);
                }
                if ($activity !== []) {
                    $item['activity'] = $activity;
                }
            }
            $out[] = $item;
        }

        return $out;
    }
}
