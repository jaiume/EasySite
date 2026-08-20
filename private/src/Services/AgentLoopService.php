<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\ChatStore;
use App\DAO\PendingTurnStore;
use App\DAO\RunRegistry;
use App\Support\Config;
use App\Support\MessageCompactor;
use App\Support\OpenRouterException;
use App\Support\ServiceResult;
use App\Support\TimeBudget;
use App\Support\ToolCallKey;
use App\Support\TurnActivity;

final class AgentLoopService
{
    public function __construct(
        private readonly Config $config,
        private readonly OpenRouterClient $openRouter,
        private readonly SpendService $spend,
        private readonly ModelCatalogService $catalog,
        private readonly FileToolService $files,
        private readonly InboxToolService $inbox,
        private readonly HttpToolService $http,
        private readonly ImageToolService $images,
        private readonly ChatStore $chats,
        private readonly CheckpointService $checkpoints,
        private readonly RunRegistry $runs,
        private readonly ToolLogger $logger,
        private readonly PendingTurnStore $pending,
        private readonly TimeBudget $timeBudget,
    ) {
    }

    /**
     * @param callable(string, array<string, mixed>): void $emit
     */
    public function run(string $userMessage, string $chatModel, string $imageModel, string $runId, callable $emit, string $previewPath = '', string $composer = ''): void
    {
        if (!$this->catalog->isAllowedChatModel($chatModel)) {
            $emit('error', ['message' => 'Chat model is not allowed.']);

            return;
        }
        if (!$this->catalog->isAllowedImageModel($imageModel)) {
            $emit('error', ['message' => 'Image model is not allowed.']);

            return;
        }

        try {
            $checkpointId = $this->checkpoints->create();
        } catch (\Throwable $e) {
            $emit('error', ['message' => 'Could not snapshot the draft for undo.']);

            return;
        }
        $messageId = bin2hex(random_bytes(8));
        $history = $this->chats->load();
        $history[] = [
            'role' => 'user',
            'content' => $userMessage,
            'composer' => $composer,
            'id' => $messageId,
            'checkpoint_id' => $checkpointId,
            'activity' => [],
        ];
        $this->chats->save($history);
        $emit('user', ['id' => $messageId]);
        $modelHistory = $this->modelMessages($history);
        if ($previewPath !== '' && $modelHistory !== []) {
            $last = count($modelHistory) - 1;
            if ($modelHistory[$last]['role'] === 'user') {
                $modelHistory[$last]['content'] = $this->previewPrefix($previewPath) . $modelHistory[$last]['content'];
            }
        }
        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt()]],
            $modelHistory,
        );
        $this->claim($runId, $chatModel, $imageModel, $messages, 0);
        $this->loopTurns($chatModel, $imageModel, $runId, $emit, $messages, $history, 0);
    }

    /**
     * Resume a stopped turn without adding another user message.
     *
     * @param callable(string, array<string, mixed>): void $emit
     */
    public function resume(string $chatModel, string $imageModel, string $runId, callable $emit): void
    {
        $pending = $this->pending->load();
        $history = $this->chats->load();
        if (is_array($pending)) {
            // Session/settings selection wins so Continue can switch models mid-turn
            // (for example to a free model after a credit error). Pending is only a fallback.
            $chatModel = $this->stringOr($chatModel, (string) ($pending['chat_model'] ?? ''));
            $imageModel = $this->stringOr($imageModel, (string) ($pending['image_model'] ?? ''));
            /** @var list<array<string, mixed>> $messages */
            $messages = $this->sanitizeMessages($pending['messages']);
            $round = (int) ($pending['round'] ?? 0);
            if ($round < 0) {
                $round = 0;
            }
            $this->ensureActivityFromMessages($history, $messages);
            $this->claim($runId, $chatModel, $imageModel, $messages, $round);
            $this->loopTurns($chatModel, $imageModel, $runId, $emit, $messages, $history, $round);

            return;
        }
        if ($history === [] || (string) ($history[array_key_last($history)]['role'] ?? '') !== 'user') {
            $emit('error', ['message' => 'Nothing to continue.']);
            $emit('done', ['ok' => false]);

            return;
        }
        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt()]],
            $this->modelMessages($history),
        );
        $this->claim($runId, $chatModel, $imageModel, $messages, 0);
        $this->loopTurns($chatModel, $imageModel, $runId, $emit, $messages, $history, 0);
    }

    /**
     * @param callable(string, array<string, mixed>): void $emit
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $history
     */
    private function loopTurns(
        string $chatModel,
        string $imageModel,
        string $runId,
        callable $emit,
        array $messages,
        array &$history,
        int $startRound,
    ): void {
        $maxRounds = $this->config->int('openrouter.max_tool_rounds', 15);
        $tools = $this->toolDefinitions();
        $deadline = microtime(true) + $this->timeBudget->requestSeconds;
        $chatTimeout = $this->timeBudget->chatSeconds;
        $didChat = false;
        for ($round = $startRound; $round < $maxRounds; $round++) {
            $messages = MessageCompactor::compact($messages);
            $this->persist($runId, $chatModel, $imageModel, $messages, $round);
            if ($this->shouldStop($runId, $deadline)) {
                $this->interrupt($emit, $runId, $chatModel, $imageModel, $messages, $history, $round);

                return;
            }
            $unanswered = $this->unansweredToolCalls($messages);
            if ($unanswered !== []) {
                $ran = $this->runToolCalls($unanswered, $messages, $chatModel, $imageModel, $runId, $emit, $history, $round, $deadline);
                if (!$ran) {
                    return;
                }
                if ($didChat) {
                    $this->interrupt($emit, $runId, $chatModel, $imageModel, $messages, $history, $round + 1);

                    return;
                }
                continue;
            }
            if ($didChat) {
                $this->interrupt($emit, $runId, $chatModel, $imageModel, $messages, $history, $round + 1);

                return;
            }
            $cap = $this->spend->assertUnderCap();
            if (!$cap['success']) {
                $this->failClosed(
                    $emit,
                    $runId,
                    $chatModel,
                    $imageModel,
                    $messages,
                    $history,
                    $round,
                    $cap['message'],
                );

                return;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining < 25) {
                $this->interrupt($emit, $runId, $chatModel, $imageModel, $messages, $history, $round);

                return;
            }
            $timeout = (int) max(15, min($chatTimeout, $remaining - 3));
            $emit('status', ['message' => 'Talking to the model…']);
            $onProgress = function () use ($emit, $runId): void {
                $this->runs->touch($runId);
                $emit('ping', []);
            };
            try {
                $response = $this->openRouter->chat($chatModel, $messages, $tools, $timeout, $onProgress);
            } catch (\Throwable $e) {
                if ($e instanceof OpenRouterException && $e->isTimeout()) {
                    $this->persist($runId, $chatModel, $imageModel, $messages, $round);
                    $this->note($history, $emit, 'Paused: ' . $e->getMessage());
                    $this->chats->save($history);
                    $emit('interrupted', ['message' => $e->getMessage(), 'can_continue' => true, 'auto_continue' => true]);
                    $emit('done', ['ok' => false, 'interrupted' => true, 'can_continue' => true, 'auto_continue' => true]);

                    return;
                }
                $details = ($e instanceof OpenRouterException) ? $e->details() : '';
                $this->failClosed(
                    $emit,
                    $runId,
                    $chatModel,
                    $imageModel,
                    $messages,
                    $history,
                    $round,
                    $e->getMessage(),
                    $details,
                );

                return;
            }
            $didChat = true;
            $cost = 0.0;
            if (isset($response['usage']['cost']) && is_numeric($response['usage']['cost'])) {
                $cost = (float) $response['usage']['cost'];
            } elseif (isset($response['usage']['total_cost']) && is_numeric($response['usage']['total_cost'])) {
                $cost = (float) $response['usage']['total_cost'];
            }
            $this->spend->record($cost, 'chat', $chatModel);

            $choice = $response['choices'][0]['message'] ?? null;
            if (!is_array($choice)) {
                $this->failClosed(
                    $emit,
                    $runId,
                    $chatModel,
                    $imageModel,
                    $messages,
                    $history,
                    $round,
                    'The model returned an empty response.',
                );

                return;
            }
            $messages[] = $this->sanitizeMessage($choice);
            $toolCalls = $choice['tool_calls'] ?? null;
            if (is_array($toolCalls) && $toolCalls !== []) {
                $aside = $choice['content'] ?? '';
                if (is_string($aside) && trim($aside) !== '') {
                    $text = TurnActivity::clip(trim($aside), 200);
                    $this->recordActivity($history, 'status', $text);
                    $emit('activity', ['kind' => 'status', 'text' => $text]);
                }
                $this->persist($runId, $chatModel, $imageModel, $messages, $round);
                $ran = $this->runToolCalls($toolCalls, $messages, $chatModel, $imageModel, $runId, $emit, $history, $round, $deadline);
                if (!$ran) {
                    return;
                }
                $this->interrupt($emit, $runId, $chatModel, $imageModel, $messages, $history, $round + 1);

                return;
            }

            $text = $choice['content'] ?? '';
            if (!is_string($text)) {
                $text = '';
            }
            $history[] = ['role' => 'assistant', 'content' => $text];
            $this->chats->save($history);
            $this->pending->clearIfRun($runId);
            $emit('message', ['text' => $text]);
            $emit('done', ['ok' => true]);

            return;
        }

        $this->pending->clearIfRun($runId);
        $history[] = ['role' => 'assistant', 'content' => 'Stopped after 15 tool rounds. Please send another message to continue.'];
        $this->chats->save($history);
        $emit('message', ['text' => 'Stopped after 15 tool rounds. Send another message to continue.']);
        $emit('done', ['ok' => true]);
    }

    /**
     * @param list<array<string, mixed>> $calls
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $history
     * @param callable(string, array<string, mixed>): void $emit
     */
    private function runToolCalls(
        array $calls,
        array &$messages,
        string $chatModel,
        string $imageModel,
        string $runId,
        callable $emit,
        array &$history,
        int $round,
        float $deadline,
    ): bool {
        $seen = ToolCallKey::completed($messages);
        $usage = ToolCallKey::fileUsage($messages);
        foreach ($calls as $call) {
            if (!is_array($call)) {
                continue;
            }
            if ($this->shouldStop($runId, $deadline)) {
                $this->interrupt($emit, $runId, $chatModel, $imageModel, $messages, $history, $round);

                return false;
            }
            $id = (string) ($call['id'] ?? '');
            $name = (string) ($call['function']['name'] ?? '');
            $rawArgs = (string) ($call['function']['arguments'] ?? '{}');
            $args = json_decode($rawArgs, true);
            if (!is_array($args)) {
                $args = [];
            }
            $fp = ToolCallKey::fingerprint($name, $args);
            $public = $this->publicArgs($name, $args);
            $path = ToolCallKey::normalizePath((string) ($args['path'] ?? '.'));
            $skipSearch = $name === 'search' ? ToolCallKey::searchBlockReason($args, $usage) : null;
            if ($skipSearch !== null) {
                $result = ServiceResult::ok($skipSearch, ['skipped' => true]);
            } elseif (isset($seen[$fp])) {
                $result = ServiceResult::ok(
                    'Already ran this exact tool call. Use that earlier result. Do not repeat it. If you are changing a file, call edit_file now.',
                    ['repeated' => true],
                );
            } else {
                $seen[$fp] = true;
                $text = TurnActivity::toolLine($name, $public);
                $this->recordActivity($history, 'tool', $text, $name);
                $emit('tool', ['name' => $name, 'args' => $public, 'text' => $text]);
                try {
                    $result = $this->dispatch($name, $args, $imageModel);
                } catch (\Throwable $e) {
                    $result = ServiceResult::fail($e->getMessage(), 'TOOL_FAILED');
                }
                if ($name === 'read_file') {
                    $usage['read'][$path] = true;
                }
                if ($name === 'search') {
                    $usage['searched'][$path] = true;
                    $usage['searches']++;
                }
            }
            $payload = json_encode($result, JSON_UNESCAPED_SLASHES);
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $id,
                'content' => is_string($payload) ? $payload : '{"success":false}',
            ];
            $this->persist($runId, $chatModel, $imageModel, $messages, $round);
        }

        return true;
    }

    /**
     * @param callable(string, array<string, mixed>): void $emit
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $history
     */
    private function interrupt(
        callable $emit,
        string $runId,
        string $chatModel,
        string $imageModel,
        array $messages,
        array &$history,
        int $round,
    ): void {
        if ($this->runs->isCancelled($runId)) {
            $this->note($history, $emit, 'Stopped.');
        }
        $this->persist($runId, $chatModel, $imageModel, $messages, $round);
        $this->chats->save($history);
        $emit('interrupted', ['message' => 'Continuing.', 'can_continue' => true, 'auto_continue' => true]);
        $emit('done', ['ok' => false, 'interrupted' => true, 'can_continue' => true, 'auto_continue' => true]);
    }

    /**
     * Stop auto-continue on a real model/provider failure. Pending stays so Continue still works.
     *
     * @param callable(string, array<string, mixed>): void $emit
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $history
     */
    private function failClosed(
        callable $emit,
        string $runId,
        string $chatModel,
        string $imageModel,
        array $messages,
        array &$history,
        int $round,
        string $message,
        string $details = '',
    ): void {
        $this->persist($runId, $chatModel, $imageModel, $messages, $round);
        $this->pending->haltAuto();
        $this->note($history, $emit, 'Paused: ' . $message);
        $this->chats->save($history);
        $payload = [
            'message' => $message,
            'can_continue' => true,
            'auto_continue' => false,
        ];
        if ($details !== '') {
            $payload['details'] = $details;
        }
        $emit('error', $payload);
        $emit('done', ['ok' => false, 'can_continue' => true, 'auto_continue' => false]);
    }

    /**
     * @param list<array<string, mixed>> $history
     * @param list<array<string, mixed>> $messages
     */
    private function ensureActivityFromMessages(array &$history, array $messages): void
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') !== 'user') {
                continue;
            }
            $existing = TurnActivity::normalize($history[$i]['activity'] ?? []);
            if ($existing !== []) {
                $history[$i]['activity'] = $existing;

                return;
            }
            $derived = TurnActivity::fromModelMessages($messages);
            if ($derived === []) {
                return;
            }
            $history[$i]['activity'] = $derived;
            $this->chats->save($history);

            return;
        }
    }

    /**
     * @param list<array<string, mixed>> $history
     * @param callable(string, array<string, mixed>): void $emit
     */
    private function note(array &$history, callable $emit, string $text): void
    {
        $text = TurnActivity::clip($text, 200);
        if (!$this->recordActivity($history, 'status', $text)) {
            return;
        }
        $emit('activity', ['kind' => 'status', 'text' => $text]);
    }

    /**
     * @param list<array<string, mixed>> $history
     */
    private function recordActivity(array &$history, string $kind, string $text, ?string $name = null): bool
    {
        $item = [
            'kind' => $kind,
            'text' => $text,
        ];
        if ($name !== null && $name !== '') {
            $item['name'] = $name;
        }
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') !== 'user') {
                continue;
            }
            $activity = TurnActivity::normalize($history[$i]['activity'] ?? []);
            $last = $activity !== [] ? $activity[array_key_last($activity)] : null;
            if (is_array($last) && ($last['kind'] ?? '') === $kind && ($last['text'] ?? '') === $text) {
                return false;
            }
            $activity[] = $item;
            if (count($activity) > TurnActivity::MAX_ITEMS) {
                $activity = array_slice($activity, -TurnActivity::MAX_ITEMS);
            }
            $history[$i]['activity'] = $activity;
            $this->chats->save($history);

            return true;
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function persist(string $runId, string $chatModel, string $imageModel, array $messages, int $round): void
    {
        $this->runs->touch($runId);
        $this->pending->saveIfCurrent($runId, [
            'chat_model' => $chatModel,
            'image_model' => $imageModel,
            'round' => $round,
            'messages' => $this->sanitizeMessages($messages),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function claim(string $runId, string $chatModel, string $imageModel, array $messages, int $round): void
    {
        $this->pending->claim($runId, [
            'chat_model' => $chatModel,
            'image_model' => $imageModel,
            'round' => $round,
            'messages' => $this->sanitizeMessages($messages),
        ]);
    }

    private function shouldStop(string $runId, float $deadline): bool
    {
        return $this->runs->isCancelled($runId)
            || (!$this->timeBudget->cli && connection_aborted())
            || microtime(true) >= $deadline;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private function unansweredToolCalls(array $messages): array
    {
        $answered = [];
        foreach ($messages as $row) {
            if (($row['role'] ?? '') === 'tool' && isset($row['tool_call_id']) && is_string($row['tool_call_id'])) {
                $answered[$row['tool_call_id']] = true;
            }
        }
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $row = $messages[$i];
            if (($row['role'] ?? '') !== 'assistant') {
                continue;
            }
            $calls = $row['tool_calls'] ?? null;
            if (!is_array($calls) || $calls === []) {
                return [];
            }
            $pending = [];
            foreach ($calls as $call) {
                if (!is_array($call)) {
                    continue;
                }
                $id = (string) ($call['id'] ?? '');
                if ($id === '' || isset($answered[$id])) {
                    continue;
                }
                $pending[] = $call;
            }

            return $pending;
        }

        return [];
    }

    private function stringOr(mixed $value, string $fallback): string
    {
        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private function sanitizeMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = $this->sanitizeMessage($row);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function sanitizeMessage(array $message): array
    {
        unset($message['reasoning'], $message['reasoning_details'], $message['refusal']);

        return $message;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toolDefinitions(): array
    {
        return [
            $this->fn('list_dir', 'List files in one staging folder.', [
                'path' => ['type' => 'string', 'description' => 'Relative path under staging. Default .'],
                'depth' => ['type' => 'integer', 'description' => 'Depth, default 1'],
            ]),
            $this->fn('read_file', 'Read a text file under staging. Binaries return name/size only.', [
                'path' => ['type' => 'string'],
            ], ['path']),
            $this->fn('write_file', 'Replace a whole text file under staging. Not for images. Prefer edit_file for a small change.', [
                'path' => ['type' => 'string'],
                'content' => ['type' => 'string'],
            ], ['path', 'content']),
            $this->fn('edit_file', 'Replace exact text in a staging file. Use replace_all true to change every match (for example a colour). Prefer this over rewriting the whole file.', [
                'path' => ['type' => 'string'],
                'old' => ['type' => 'string', 'description' => 'Exact text to find'],
                'new' => ['type' => 'string', 'description' => 'Replacement text'],
                'replace_all' => ['type' => 'boolean', 'description' => 'Replace every match. Default false (fails if old matches more than once).'],
            ], ['path', 'old', 'new']),
            $this->fn('search', 'Find text only in files you have not already read. Prefer read_file then edit_file. Do not search the same file twice.', [
                'query' => ['type' => 'string', 'description' => 'Literal text, or several literals separated by |'],
                'path' => ['type' => 'string'],
            ], ['query']),
            $this->fn('mkdir', 'Create a directory under staging.', [
                'path' => ['type' => 'string'],
            ], ['path']),
            $this->fn('rename', 'Rename a staging file or folder.', [
                'from' => ['type' => 'string'],
                'to' => ['type' => 'string'],
            ], ['from', 'to']),
            $this->fn('delete', 'Delete a staging file or folder.', [
                'path' => ['type' => 'string'],
            ], ['path']),
            $this->fn('fetch_page', 'GET one public https HTML page and return title plus extracted text. CSS/JS URLs return a short excerpt only. Images must use fetch_image. For colours and layout use inspect_page.', [
                'url' => ['type' => 'string'],
            ], ['url']),
            $this->fn('inspect_page', 'Learn colours, fonts, and header/footer layout from a public https page. Does not copy vendor CSS. Use this instead of downloading Joomla or theme stylesheets.', [
                'url' => ['type' => 'string'],
            ], ['url']),
            $this->fn('list_site', 'Crawl https URLs under the same host and path prefix.', [
                'url' => ['type' => 'string'],
                'depth' => ['type' => 'integer'],
            ], ['url']),
            $this->fn('fetch_image', 'Download a public https image into staging/images/. Returns filename only.', [
                'url' => ['type' => 'string'],
                'filename' => ['type' => 'string'],
            ], ['url']),
            $this->fn('generate_image', 'Generate an image with the selected image model and save it under staging/images/.', [
                'prompt' => ['type' => 'string'],
                'filename' => ['type' => 'string'],
                'aspect_ratio' => ['type' => 'string'],
            ], ['prompt']),
            $this->fn('list_inbox', 'List files the owner dropped into chat. These are NOT on the draft site until imported.', []),
            $this->fn('read_inbox', 'Read a dropped chat file from the private inbox. Binaries return name/size only.', [
                'path' => ['type' => 'string'],
            ], ['path']),
            $this->fn('import_to_staging', 'Copy an inbox file onto the draft site. Use when the owner wants it on the website. Images typically to images/<filename>.', [
                'from' => ['type' => 'string', 'description' => 'Inbox filename'],
                'to' => ['type' => 'string', 'description' => 'Staging relative path, e.g. images/hero.jpg'],
            ], ['from']),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    private function dispatch(string $name, array $args, string $imageModel): array
    {
        return match ($name) {
            'list_dir' => $this->files->listDir($args),
            'read_file' => $this->files->readFile($args),
            'write_file' => $this->files->writeFile($args),
            'edit_file' => $this->files->editFile($args),
            'search' => $this->files->search($args),
            'mkdir' => $this->files->mkdir($args),
            'rename' => $this->files->rename($args),
            'delete' => $this->files->delete($args),
            'fetch_page' => $this->http->fetchPage($args),
            'inspect_page' => $this->http->inspectPage($args),
            'list_site' => $this->http->listSite($args),
            'fetch_image' => $this->http->fetchImage($args),
            'generate_image' => $this->images->generateImage($args, $imageModel),
            'list_inbox' => $this->inbox->listInbox($args),
            'read_inbox' => $this->inbox->readInbox($args),
            'import_to_staging' => $this->inbox->importToStaging($args),
            default => ServiceResult::fail('Unknown tool.', 'UNKNOWN_TOOL'),
        };
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private function fn(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties === [] ? new \stdClass() : $properties,
                    'required' => $required,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function publicArgs(string $name, array $args): array
    {
        unset($args['content'], $args['new']);

        return $args;
    }

    private function systemPrompt(): string
    {
        $prompt = $this->readConfigText('system-prompt.md', 'Edit staging files only.');
        $standards = $this->readConfigText('coding_standards.md', '');
        if ($standards === '') {
            return $prompt;
        }

        return $prompt . "\n\n" . $standards;
    }

    private function readConfigText(string $filename, string $fallback): string
    {
        $path = $this->config->baseDir() . '/config/' . $filename;
        if (!is_readable($path)) {
            return $fallback;
        }
        $text = trim((string) file_get_contents($path));

        return $text !== '' ? $text : $fallback;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return list<array{role: string, content: string}>
     */
    private function modelMessages(array $history): array
    {
        $out = [];
        foreach ($history as $row) {
            $role = (string) ($row['role'] ?? '');
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            $out[] = [
                'role' => $role,
                'content' => (string) ($row['content'] ?? ''),
            ];
        }

        return $out;
    }

    private function previewPrefix(string $previewPath): string
    {
        return "The owner is currently looking at this draft page in the preview: {$previewPath}\n"
            . "If they say \"this page\", \"here\", or similar, they mean that file unless they name another.\n\n";
    }
}
