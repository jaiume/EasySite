<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\ConversationService;
use App\Services\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class AppController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly CsrfService $csrf,
        private readonly ConversationService $conversation,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return $this->twig->render($response, 'app.twig', [
            'csrf' => $this->csrf->token(),
            'staging_url' => '/staging/',
            'flash' => is_array($flash) ? $flash : null,
            'history' => $this->conversation->displayMessages(),
            'can_continue' => $this->conversation->canContinue(),
        ]);
    }
}
