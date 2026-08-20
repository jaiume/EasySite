<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\DAO\RunRegistry;
use App\Services\ConversationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RestoreController
{
    public function __construct(
        private readonly ConversationService $conversation,
        private readonly RunRegistry $runs,
    ) {
    }

    public function restore(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->runs->hasActive()) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Wait until the current run finishes before restoring.',
                'data' => null,
                'error' => ['code' => 'RUN_IN_PROGRESS', 'details' => []],
            ], JSON_UNESCAPED_SLASHES));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
        }
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $decoded = json_decode((string) $request->getBody(), true);
            $body = is_array($decoded) ? $decoded : [];
        }
        $messageId = trim((string) ($body['message_id'] ?? ''));
        if ($messageId === '') {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Choose a message to restore.',
                'data' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'details' => []],
            ], JSON_UNESCAPED_SLASHES));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
        $result = $this->conversation->restoreToMessage($messageId);
        $status = $result['success'] ? 200 : 400;
        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
