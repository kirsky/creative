<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;

/**
 * The allow-list of routes that need no session. Every route is protected unless
 * it is registered through PublicRoute::mark(); the authentication middleware asks isPublic().
 */
final class PublicRoute
{
    private const ARGUMENT = '_public';

    public static function mark(RouteInterface $route): RouteInterface
    {
        return $route->setArgument(self::ARGUMENT, '1');
    }

    /**
     * False for routes that are not marked, and for requests that have not been routed.
     */
    public static function isPublic(ServerRequestInterface $request): bool
    {
        $route = $request->getAttribute(RouteContext::ROUTE);

        return $route instanceof RouteInterface && $route->getArgument(self::ARGUMENT) === '1';
    }
}
