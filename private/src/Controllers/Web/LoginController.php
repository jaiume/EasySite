<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\AuthService;
use App\Services\CsrfService;
use App\Services\RateLimitService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class LoginController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly AuthService $auth,
        private readonly CsrfService $csrf,
        private readonly RateLimitService $rateLimit,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->auth->check()) {
            return $response->withHeader('Location', '/cp/')->withStatus(302);
        }

        return $this->twig->render($response, 'login.twig', [
            'csrf' => $this->csrf->token(),
            'error' => null,
        ]);
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        if (!$this->rateLimit->hit('login', is_string($ip) ? $ip : 'unknown')) {
            return $this->twig->render($response->withStatus(429), 'login.twig', [
                'csrf' => $this->csrf->token(),
                'error' => 'Too many attempts. Try again later.',
            ]);
        }
        $body = $request->getParsedBody();
        $username = is_array($body) ? trim((string) ($body['username'] ?? '')) : '';
        $password = is_array($body) ? (string) ($body['password'] ?? '') : '';
        $result = $this->auth->attempt($username, $password);
        if (!$result['success']) {
            return $this->twig->render($response->withStatus(401), 'login.twig', [
                'csrf' => $this->csrf->token(),
                'error' => $result['message'],
            ]);
        }

        return $response->withHeader('Location', '/cp/')->withStatus(302);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->auth->logout();
        session_regenerate_id(true);

        return $response->withHeader('Location', '/cp/login')->withStatus(302);
    }
}
