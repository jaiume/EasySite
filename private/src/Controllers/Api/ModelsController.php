<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\ModelCatalogService;
use App\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ModelsController
{
    public function __construct(
        private readonly ModelCatalogService $catalog,
        private readonly Config $config,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, $this->catalog->catalog(), 'Model catalog');
    }

    public function refresh(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->catalog->refresh();
        if ($data['chat'] === [] && $data['image'] === []) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Could not load models from OpenRouter. Check the API key and try again.',
                'data' => null,
                'error' => ['code' => 'CATALOG_EMPTY', 'details' => []],
            ], JSON_UNESCAPED_SLASHES));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(502);
        }

        return $this->json($response, $data, 'Model catalog refreshed.');
    }

    /**
     * @param array{chat: list<array{id: string, name: string, price: string}>, image: list<array{id: string, name: string, price: string}>} $catalog
     */
    private function json(ResponseInterface $response, array $catalog, string $message): ResponseInterface
    {
        $catalog['defaults'] = [
            'chat' => $this->config->string('openrouter.default_chat_model'),
            'image' => $this->config->string('openrouter.default_image_model'),
        ];
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => $message,
            'data' => $catalog,
            'error' => null,
        ], JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
