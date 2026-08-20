<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CsrfService $csrf,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $handler->handle($request);
        }
        $token = $this->extractToken($request);
        if (!$this->csrf->validate($token)) {
            $path = $request->getUri()->getPath();
            if (str_contains($path, '/api/')) {
                $response = new Response(403);
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'CSRF check failed. Reload the page and try again.',
                    'data' => null,
                    'error' => ['code' => 'CSRF', 'details' => []],
                ]));

                return $response->withHeader('Content-Type', 'application/json');
            }
            $response = new Response(403);
            $response->getBody()->write('CSRF check failed. Reload the page and try again.');

            return $response->withHeader('Content-Type', 'text/plain');
        }

        return $handler->handle($request);
    }

    private function extractToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('X-CSRF-Token');
        if ($header !== '') {
            return $header;
        }
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && isset($parsed['_csrf']) && is_string($parsed['_csrf'])) {
            return $parsed['_csrf'];
        }

        return null;
    }
}
