<?php

declare(strict_types=1);

/**
 * Anonym Mail — Webmail Entry Point
 */

require __DIR__ . '/../../app/vendor/autoload.php';

use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SessionMiddleware;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../../app/src/config/container.php');
$containerBuilder->addDefinitions(__DIR__ . '/../src/config/container.php');
$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->setBasePath('/webmail');

// Middleware (LIFO: last added = first executed)
$app->addRoutingMiddleware();
$app->add($container->get(CsrfMiddleware::class));
$app->add($container->get(SessionMiddleware::class));
$app->add($container->get(SecurityHeadersMiddleware::class));

$errorMiddleware = $app->addErrorMiddleware(
    displayErrorDetails: (bool) ($_ENV['APP_DEBUG'] ?? false),
    logErrors: false,
    logErrorDetails: false
);

require __DIR__ . '/../src/routes.php';

$app->run();
