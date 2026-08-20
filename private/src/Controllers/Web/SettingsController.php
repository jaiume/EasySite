<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\CsrfService;
use App\Services\ModelCatalogService;
use App\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;

final class SettingsController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly CsrfService $csrf,
        private readonly ModelCatalogService $catalog,
        private readonly Config $config,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $catalog = $this->catalog->catalog();
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return $this->twig->render($response, 'settings.twig', [
            'csrf' => $this->csrf->token(),
            'api_key' => $this->config->string('openrouter.api_key'),
            'chat_models' => $catalog['chat'],
            'image_models' => $catalog['image'],
            'chat_model' => $this->currentChatModel(),
            'image_model' => $this->currentImageModel(),
            'flash' => is_array($flash) ? $flash : null,
        ]);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $apiKey = is_array($body) ? trim((string) ($body['api_key'] ?? '')) : '';
        if (str_contains($apiKey, "\n") || str_contains($apiKey, "\r")) {
            $_SESSION['flash'] = ['ok' => false, 'message' => 'API key cannot contain line breaks.'];

            return (new Response(302))->withHeader('Location', '/cp/settings');
        }
        $chat = is_array($body) ? trim((string) ($body['chat_model'] ?? '')) : '';
        $image = is_array($body) ? trim((string) ($body['image_model'] ?? '')) : '';
        if ($chat === '' || !$this->catalog->isAllowedChatModel($chat)) {
            $_SESSION['flash'] = ['ok' => false, 'message' => 'Choose a valid chat model.'];

            return (new Response(302))->withHeader('Location', '/cp/settings');
        }
        if ($image === '' || !$this->catalog->isAllowedImageModel($image)) {
            $_SESSION['flash'] = ['ok' => false, 'message' => 'Choose a valid image model.'];

            return (new Response(302))->withHeader('Location', '/cp/settings');
        }
        try {
            $this->config->writeString('openrouter', 'api_key', $apiKey);
        } catch (\Throwable $e) {
            $_SESSION['flash'] = ['ok' => false, 'message' => 'Could not save the API key to config.ini.'];

            return (new Response(302))->withHeader('Location', '/cp/settings');
        }
        $_SESSION['chat_model'] = $chat;
        $_SESSION['image_model'] = $image;
        $_SESSION['flash'] = ['ok' => true, 'message' => 'Settings saved.'];

        return (new Response(302))->withHeader('Location', '/cp/settings');
    }

    private function currentChatModel(): string
    {
        $value = $_SESSION['chat_model'] ?? $this->config->string('openrouter.default_chat_model');

        return is_string($value) ? $value : '';
    }

    private function currentImageModel(): string
    {
        $value = $_SESSION['image_model'] ?? $this->config->string('openrouter.default_image_model');

        return is_string($value) ? $value : '';
    }
}
