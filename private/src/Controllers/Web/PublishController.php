<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\ConversationService;
use App\Services\CsrfService;
use App\Services\PublishService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;

final class PublishController
{
    public function __construct(
        private readonly PublishService $publishService,
        private readonly ConversationService $conversation,
        private readonly Twig $twig,
        private readonly CsrfService $csrf,
    ) {
    }

    public function publish(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $confirm = is_array($body) ? trim((string) ($body['confirm'] ?? '')) : '';
        if ($confirm !== 'PUBLISH') {
            return $this->fail($response, 'Type PUBLISH to confirm.');
        }
        $result = $this->publishService->publish();
        if ($result['success']) {
            $this->conversation->clear();
            $result['message'] .= ' Chat and undo history were cleared.';
        }

        return $this->redirectWithFlash($result['message'], $result['success']);
    }

    public function rollback(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $confirm = is_array($body) ? trim((string) ($body['confirm'] ?? '')) : '';
        if ($confirm !== 'ROLLBACK') {
            return $this->fail($response, 'Type ROLLBACK to confirm.');
        }
        $result = $this->publishService->rollback();

        return $this->redirectWithFlash($result['message'], $result['success']);
    }

    private function fail(ResponseInterface $response, string $message): ResponseInterface
    {
        $_SESSION['flash'] = ['ok' => false, 'message' => $message];

        return $response->withHeader('Location', '/cp/')->withStatus(302);
    }

    private function redirectWithFlash(string $message, bool $ok): ResponseInterface
    {
        $_SESSION['flash'] = ['ok' => $ok, 'message' => $message];

        return (new Response(302))->withHeader('Location', '/cp/');
    }
}
