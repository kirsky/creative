<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Http\PublicRoute;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PublicRouteTest extends TestCase
{
    public function test_only_routes_marked_public_are_public(): void
    {
        /** @var list<bool> $seen */
        $seen = [];

        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->add(function (ServerRequestInterface $request, RequestHandlerInterface $next) use (&$seen): ResponseInterface {
            $seen[] = PublicRoute::isPublic($request);

            return $next->handle($request);
        });
        $app->addRoutingMiddleware();
        PublicRoute::mark($app->get('/open', static fn(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => $response));
        $app->get('/closed', static fn(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => $response);

        $factory = new ServerRequestFactory();
        $app->handle($factory->createServerRequest('GET', '/open'));
        $app->handle($factory->createServerRequest('GET', '/closed'));

        self::assertSame([true, false], $seen);
    }

    public function test_a_request_that_was_never_routed_is_not_public(): void
    {
        self::assertFalse(PublicRoute::isPublic((new ServerRequestFactory())->createServerRequest('GET', '/open')));
    }
}
