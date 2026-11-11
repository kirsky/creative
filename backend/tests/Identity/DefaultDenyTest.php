<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Shared\Http\PublicRoute;
use App\Tests\Support\ApiTestCase;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;

/**
 * Routes are protected by default. These tests walk every route the application registers, so a
 * route added by any later task is covered the moment it exists: it must answer 401 without a
 * valid session, and document that 401 in openapi.yaml.
 */
final class DefaultDenyTest extends ApiTestCase
{
    /** The whole public allow-list. Adding to it is a security decision, not a convenience. */
    private const PUBLIC = ['POST /api/auth/login', 'GET /api/health'];

    private const UNAUTHENTICATED = '{"error":{"code":"unauthenticated","fields":[]}}';

    public function test_every_registered_route_except_login_and_health_returns_401(): void
    {
        $checked = 0;

        foreach ($this->protectedEndpoints() as [$method, $path]) {
            $response = $this->handle($this->buildRequest($method, $path));

            self::assertSame(401, $response->getStatusCode(), sprintf('%s %s is reachable without a session.', $method, $path));
            self::assertSame(self::UNAUTHENTICATED, (string) $response->getBody(), sprintf('%s %s', $method, $path));
            $this->assertMatchesSpec($response);
            ++$checked;
        }

        // Login, logout, me and locale exist today; the walk must not silently find nothing.
        self::assertGreaterThanOrEqual(3, $checked);
    }

    public function test_an_unknown_or_expired_session_is_rejected_on_every_protected_route(): void
    {
        [$expired] = $this->startSession($this->createUser('anna'));
        $this->clock->advance('PT12H');

        foreach ($this->protectedEndpoints() as [$method, $path]) {
            foreach ([$expired, str_repeat('f', 64)] as $token) {
                $response = $this->handle(self::withSession($this->buildRequest($method, $path), $token, 'irrelevant'));

                self::assertSame(401, $response->getStatusCode(), sprintf('%s %s', $method, $path));
                self::assertSame(self::UNAUTHENTICATED, (string) $response->getBody());
            }
        }
    }

    public function test_the_public_allow_list_is_exactly_login_and_health(): void
    {
        $public = [];
        $registered = [];

        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $endpoint = $method . ' ' . $route->getPattern();
                $registered[] = $endpoint;

                if (self::isPublic($route)) {
                    $public[] = $endpoint;
                }
            }
        }

        self::assertEqualsCanonicalizing(self::PUBLIC, $public);
        foreach (self::PUBLIC as $endpoint) {
            self::assertContains($endpoint, $registered);
        }
    }

    public function test_public_routes_answer_without_a_session(): void
    {
        self::assertSame(200, $this->request('GET', '/api/health')->getStatusCode());

        // Not 401: the login route runs, and fails on its incomplete body.
        $login = $this->request('POST', '/api/auth/login', ['username' => 'nobody']);
        self::assertSame(422, $login->getStatusCode());
        $this->assertMatchesSpec($login);
    }

    /**
     * Every method and path of every registered route that is not on the allow-list, with
     * placeholders filled in with a value their pattern accepts.
     *
     * @return list<array{string, string}>
     */
    private function protectedEndpoints(): array
    {
        $endpoints = [];

        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                if (in_array($method . ' ' . $route->getPattern(), self::PUBLIC, true)) {
                    continue;
                }

                self::assertFalse(self::isPublic($route), sprintf('%s %s is marked public but is not on the allow-list.', $method, $route->getPattern()));
                $endpoints[] = [$method, self::samplePath($route->getPattern())];
            }
        }

        return $endpoints;
    }

    private static function isPublic(RouteInterface $route): bool
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');

        return PublicRoute::isPublic($request->withAttribute(RouteContext::ROUTE, $route));
    }

    /**
     * "/api/tasks/{id:\d+}[/rounds]" becomes "/api/tasks/1/rounds": optional segments are
     * included and each placeholder gets the first sample its pattern accepts.
     */
    private static function samplePath(string $pattern): string
    {
        $path = preg_replace_callback(
            '/\{\s*([a-zA-Z_][a-zA-Z0-9_-]*)\s*(?::((?:[^{}]|\{[^{}]*\})*))?\}/',
            static function (array $placeholder): string {
                $regex = isset($placeholder[2]) && $placeholder[2] !== '' ? $placeholder[2] : '[^/]+';

                foreach (['1', 'a', 'abc', 'file.png', '2026-01-15', 'en'] as $sample) {
                    if (preg_match('~^(?:' . $regex . ')$~', $sample) === 1) {
                        return $sample;
                    }
                }

                self::fail(sprintf('No sample value matches the placeholder {%s:%s}; add one to samplePath().', $placeholder[1], $regex));
            },
            $pattern,
        );

        return str_replace(['[', ']'], '', $path ?? $pattern);
    }
}
