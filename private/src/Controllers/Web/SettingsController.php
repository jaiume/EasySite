<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Services\CsrfService;
use App\Services\LogExportService;
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
        private readonly LogExportService $logs,
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
            'log_files' => $this->logs->summaries(),
            'flash' => is_array($flash) ? $flash : null,
        ]);
    }

    public function exportLogs(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $bundle = $this->logs->build();
        } catch (\Throwable $e) {
            $_SESSION['flash'] = ['ok' => false, 'message' => 'Could not build the log export.'];

            return (new Response(302))->withHeader('Location', '/cp/settings');
        }
        $download = new Response(200);
        $download->getBody()->write($bundle['bytes']);

        return $download
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $bundle['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($bundle['bytes']))
            ->withHeader('Cache-Control', 'no-store');
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
            $this->config->writeString('openrouter', 'default_chat_model', $chat);
            $this->config->writeString('openrouter', 'default_image_model', $image);
        } catch (\Throwable $e) {
            $_SESSION['flash'] = ['ok' => false, 'message' => 'Could not save settings to config.ini.'];

            return (new Response(302))->withHeader('Location', '/cp/settings');
        }
        $_SESSION['flash'] = ['ok' => true, 'message' => 'Settings saved.'];

        return (new Response(302))->withHeader('Location', '/cp/settings');
    }

    private function currentChatModel(): string
    {
        return $this->config->string('openrouter.default_chat_model');
    }

    private function currentImageModel(): string
    {
        return $this->config->string('openrouter.default_image_model');
    }
}
