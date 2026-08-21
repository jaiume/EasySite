<?php

declare(strict_types=1);

use App\Controllers\Api\AttachmentsController;
use App\Controllers\Api\CancelController;
use App\Controllers\Api\ChatController;
use App\Controllers\Api\ModelsController;
use App\Controllers\Api\RestoreController;
use App\Controllers\Api\SpendController;
use App\Controllers\Web\AppController;
use App\Controllers\Web\LoginController;
use App\Controllers\Web\PreviewController;
use App\Controllers\Web\PublishController;
use App\Controllers\Web\SettingsController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/login', [LoginController::class, 'show']);
    $app->post('/login', [LoginController::class, 'submit'])->add(CsrfMiddleware::class);

    $app->group('', function (RouteCollectorProxy $group): void {
        $group->get('/', [AppController::class, 'index']);
        $group->get('/preview[/{path:.*}]', [PreviewController::class, 'show']);
        $group->get('/settings', [SettingsController::class, 'show']);
        $group->post('/settings', [SettingsController::class, 'save']);
        $group->post('/settings/password', [SettingsController::class, 'changePassword']);
        $group->post('/settings/logs', [SettingsController::class, 'exportLogs']);
        $group->post('/logout', [LoginController::class, 'logout']);
        $group->post('/publish', [PublishController::class, 'publish']);
        $group->post('/rollback', [PublishController::class, 'rollback']);
        $group->get('/api/models', [ModelsController::class, 'index']);
        $group->post('/api/models/refresh', [ModelsController::class, 'refresh']);
        $group->get('/api/spend', [SpendController::class, 'index']);
        $group->post('/api/attachments', [AttachmentsController::class, 'create']);
        $group->post('/api/chat', [ChatController::class, 'chat']);
        $group->get('/api/turn', [ChatController::class, 'status']);
        $group->post('/api/restore', [RestoreController::class, 'restore']);
        $group->post('/api/cancel', [CancelController::class, 'cancel']);
    })->add(CsrfMiddleware::class)->add(AuthMiddleware::class);
};
