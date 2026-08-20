<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\DAO\PendingTurnStore;
use App\DAO\RunRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CancelController
{
    public function __construct(
        private readonly RunRegistry $runs,
        private readonly PendingTurnStore $pending,
    ) {
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $decoded = json_decode((string) $request->getBody(), true);
            $body = is_array($decoded) ? $decoded : [];
        }
        $id = (string) ($body['run_id'] ?? '');
        $this->runs->cancel($id);
        $haltAuto = ($body['halt_auto'] ?? true) !== false;
        if ($haltAuto) {
            $this->pending->haltAuto();
        }
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Stop requested.',
            'data' => ['run_id' => $id],
            'error' => null,
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
