<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $auth,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->auth->check()) {
            return $handler->handle($request);
        }
        $path = $request->getUri()->getPath();
        if (str_contains($path, '/api/')) {
            $response = new Response(403);
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Not signed in.',
                'data' => null,
                'error' => ['code' => 'UNAUTHENTICATED', 'details' => []],
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return (new Response(302))->withHeader('Location', '/cp/login');
    }
}
