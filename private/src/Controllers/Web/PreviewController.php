<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\DraftViewService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class PreviewController
{
    public function __construct(
        private readonly DraftViewService $drafts,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $path = (string) ($args['path'] ?? '');
        $query = $request->getQueryParams();
        $result = $this->drafts->http($path, is_array($query) ? $query : []);
        $out = new Response($result['status']);
        $out->getBody()->write($result['body']);
        foreach ($result['headers'] as $name => $value) {
            $out = $out->withHeader($name, $value);
        }

        return $out;
    }
}
