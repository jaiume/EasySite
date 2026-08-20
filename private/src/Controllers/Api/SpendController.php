<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\SpendService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SpendController
{
    public function __construct(
        private readonly SpendService $spend,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->spend->status();
        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
