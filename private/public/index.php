<?php

declare(strict_types=1);

use App\Middleware\SessionMiddleware;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

$baseDir = dirname(__DIR__);

require $baseDir . '/vendor/autoload.php';

$builder = new ContainerBuilder();
$builder->addDefinitions($baseDir . '/config/container.php');
$builder->addDefinitions([
    Twig::class => static function () use ($baseDir): Twig {
        $cache = $baseDir . '/var/cache/twig';
        if (!is_dir($cache)) {
            mkdir($cache, 0770, true);
        }

        $twig = Twig::create($baseDir . '/templates', [
            'cache' => $cache,
            'auto_reload' => true,
        ]);
        $assets = dirname($baseDir) . '/public_html/cp/assets';
        $env = $twig->getEnvironment();
        $env->addGlobal('asset_css_v', (int) (@filemtime($assets . '/app.css') ?: time()));
        $env->addGlobal('asset_js_v', (int) (@filemtime($assets . '/app.js') ?: time()));

        return $twig;
    },
]);
$container = $builder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->setBasePath('/cp');
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(TwigMiddleware::create($app, $container->get(Twig::class)));
$app->add(SessionMiddleware::class);

$errorMiddleware = $app->addErrorMiddleware(false, true, true);

$routes = require $baseDir . '/config/routes.php';
$routes($app);

$app->run();
