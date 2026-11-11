<?php

declare(strict_types=1);

namespace App;

use App\Shared\Http\ErrorMiddleware;
use App\Shared\Http\TrustedProxyMiddleware;
use Closure;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Builds the service container from config/container.php.
 *
 * @param array<string, mixed> $overrides container definitions that replace the defaults (used by tests)
 */
function createContainer(array $overrides = []): ContainerInterface
{
    /** @var Closure(array<string, mixed>): ContainerInterface $factory */
    $factory = require __DIR__ . '/../config/container.php';

    return $factory($overrides);
}

/**
 * Builds the Slim application: container, middleware, routes. Nothing is sent or read from the
 * environment here beyond what the container does, so tests can build as many apps as they need.
 *
 * @param array<string, mixed> $overrides container definitions that replace the defaults (used by tests)
 * @return App<ContainerInterface>
 */
function createApp(array $overrides = []): App
{
    $container = createContainer($overrides);
    $app = AppFactory::createFromContainer($container);

    // Middleware runs outermost first, which is the reverse of the order it is added in.
    //
    // Innermost: per-request middleware that needs the matched route and may throw ApiError
    // (sessions, authentication, CSRF) is added here, before the routing middleware.
    $app->addBodyParsingMiddleware();
    $app->addRoutingMiddleware();
    // Catches everything thrown inside, including routing failures.
    $app->add(ErrorMiddleware::class);
    // Outermost: knows the real client address before anything else runs, logging included.
    $app->add(TrustedProxyMiddleware::class);

    /** @var Closure(App<ContainerInterface>): void $routes */
    $routes = require __DIR__ . '/../config/routes.php';
    $routes($app);

    return $app;
}
