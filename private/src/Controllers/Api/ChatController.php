<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\DAO\PendingTurnStore;
use App\DAO\RunRegistry;
use App\Services\AgentLoopService;
use App\Services\AttachmentService;
use App\Services\ConversationService;
use App\Support\Config;
use App\Support\TimeBudget;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ChatController
{
    public function __construct(
        private readonly AgentLoopService $loop,
        private readonly RunRegistry $runs,
        private readonly AttachmentService $attachments,
        private readonly ConversationService $conversation,
        private readonly PendingTurnStore $pending,
        private readonly TimeBudget $timeBudget,
        private readonly Config $config,
    ) {
    }

    public function chat(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $raw = (string) $request->getBody();
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }
        $typed = trim((string) ($body['message'] ?? ''));
        $message = $typed;
        $attachmentPaths = $body['attachments'] ?? [];
        if (!is_array($attachmentPaths)) {
            $attachmentPaths = [];
        }
        /** @var list<string> $paths */
        $paths = [];
        foreach ($attachmentPaths as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }
        if ($paths !== []) {
            $built = $this->attachments->promptFromPaths($paths);
            if (!$built['success']) {
                $response->getBody()->write(json_encode($built, JSON_UNESCAPED_SLASHES));

                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            $prefix = (string) ($built['data']['prompt'] ?? '');
            $message = $prefix === '' ? $message : $prefix . ($message !== '' ? "\n\n" . $message : '');
        }
        $previewPath = $this->previewPath(is_array($body) ? (string) ($body['preview_path'] ?? '') : '');
        $chatModel = $this->config->string('openrouter.default_chat_model');
        $imageModel = $this->config->string('openrouter.default_image_model');
        $resume = !empty($body['continue']);
        if (!$resume && $message === '') {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Type a message or attach a file.',
                'data' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'details' => []],
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
        $this->runs->cancelActive();
        $runId = $this->runs->start();
        if ($resume) {
            $this->pending->resumeAuto();
        }
        $this->timeBudget->applyPhpLimit();
        ignore_user_abort(false);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        $emit = static function (string $event, array $data) use ($runId): void {
            $data['run_id'] = $runId;
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $json = '{"message":"Could not encode error details.","run_id":' . json_encode($runId) . '}';
            }
            echo 'event: ' . $event . "\n";
            echo 'data: ' . $json . "\n\n";
            flush();
        };

        $emit('run', ['run_id' => $runId]);
        try {
            if ($resume) {
                $this->loop->resume($chatModel, $imageModel, $runId, $emit);
            } else {
                $this->loop->run($message, $chatModel, $imageModel, $runId, $emit, $previewPath, $typed);
            }
        } finally {
            $this->runs->finish($runId);
        }

        return $response->withHeader('Content-Type', 'text/event-stream');
    }

    public function status(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = [
            'success' => true,
            'message' => '',
            'data' => [
                'history' => $this->conversation->displayMessages(),
                'can_continue' => $this->conversation->canContinue(),
                'auto_continue' => $this->pending->allowsAutoContinue(),
                'running' => $this->runs->hasActive(90),
                'run_id' => $this->runs->activeId(90),
            ],
            'error' => null,
        ];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function previewPath(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (strlen($raw) > 500) {
            $raw = substr($raw, 0, 500);
        }
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            $path = (string) (parse_url($raw, PHP_URL_PATH) ?: '');
            $query = parse_url($raw, PHP_URL_QUERY);
            $raw = $path . (is_string($query) && $query !== '' ? '?' . $query : '');
        }
        $raw = str_replace('\\', '/', $raw);
        if (str_contains($raw, '..')) {
            return '';
        }
        $parts = parse_url($raw);
        $path = is_array($parts) && isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : $raw;
        $query = is_array($parts) && isset($parts['query']) && is_string($parts['query']) ? $parts['query'] : '';
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        if (str_starts_with($path, '/staging')) {
            $rel = ltrim(substr($path, strlen('/staging')), '/');
        } else {
            $rel = ltrim($path, '/');
        }
        $shown = ($rel === '' || $rel === 'index.php') ? '/' : $rel;
        if ($query !== '') {
            $shown .= '?' . $query;
        }
        if ($shown !== '/' && preg_match('/[^A-Za-z0-9._\/?=&%\-]/', $shown) === 1) {
            return '';
        }

        return $shown;
    }
}
