<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Shared\Http\PublicRoute;
use App\Tests\Support\ApiTestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;

/**
 * An authenticated request that changes state must carry the session's CSRF token in X-CSRF-Token.
 */
final class CsrfTest extends ApiTestCase
{
    private const FORBIDDEN = '{"error":{"code":"forbidden","fields":[]}}';

    public function test_post_without_x_csrf_token_is_403_forbidden(): void
    {
        [$token] = $this->startSession($this->createUser('anna'));

        $response = $this->handle(self::withSession($this->buildRequest('POST', '/api/auth/logout'), $token));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(self::FORBIDDEN, (string) $response->getBody());
        $this->assertMatchesSpec($response);

        // Refused before the action ran: the session is still alive.
        self::assertSame(200, $this->me($token)->getStatusCode());
    }

    public function test_with_token_passes(): void
    {
        [$token, $csrf] = $this->startSession($this->createUser('anna'));

        $locale = $this->handle(self::withSession($this->buildRequest('PATCH', '/api/me/locale', ['locale' => 'ru']), $token, $csrf));
        self::assertSame(200, $locale->getStatusCode());
        $this->assertMatchesSpec($locale);

        $logout = $this->handle(self::withSession($this->buildRequest('POST', '/api/auth/logout'), $token, $csrf));
        self::assertSame(204, $logout->getStatusCode());
    }

    public function test_wrong_or_foreign_token_is_403_forbidden(): void
    {
        $user = $this->createUser('anna');
        [$token, $csrf] = $this->startSession($user);
        [, $otherSessionsCsrf] = $this->startSession($this->createUser('bob'));

        foreach (['', 'x', strtoupper($csrf), $csrf . '0', substr($csrf, 1), $otherSessionsCsrf, $token] as $wrong) {
            $response = $this->handle(
                self::withSession($this->buildRequest('PATCH', '/api/me/locale', ['locale' => 'ru']), $token)
                    ->withHeader('X-CSRF-Token', $wrong),
            );

            self::assertSame(403, $response->getStatusCode(), sprintf('token "%s"', $wrong));
            self::assertSame(self::FORBIDDEN, (string) $response->getBody());
        }

        self::assertSame('en', $this->decodeMe($this->me($token))['locale']);
    }

    public function test_safe_methods_need_no_token(): void
    {
        [$token] = $this->startSession($this->createUser('anna'));

        self::assertSame(200, $this->me($token)->getStatusCode());
    }

    public function test_request_helper_sends_session_and_token(): void
    {
        $response = $this->request('PATCH', '/api/me/locale', ['locale' => 'ru'], as: $this->createUser('anna'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_every_state_changing_protected_route_requires_the_token(): void
    {
        [$token] = $this->startSession($this->createUser('anna'));
        $factory = new ServerRequestFactory();
        $checked = 0;

        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            $probe = $factory->createServerRequest('GET', '/')->withAttribute(RouteContext::ROUTE, $route);
            if (PublicRoute::isPublic($probe)) {
                continue;
            }

            foreach (array_diff($route->getMethods(), ['GET', 'HEAD', 'OPTIONS']) as $method) {
                $path = preg_replace('/\{[^}]+\}/', '1', str_replace(['[', ']'], '', $route->getPattern())) ?? '';
                $response = $this->handle(self::withSession($this->buildRequest($method, $path), $token));

                self::assertSame(403, $response->getStatusCode(), sprintf('%s %s runs without a CSRF token.', $method, $route->getPattern()));
                self::assertSame(self::FORBIDDEN, (string) $response->getBody());
                ++$checked;
            }
        }

        self::assertGreaterThanOrEqual(2, $checked);
    }

    private function me(string $token): ResponseInterface
    {
        return $this->handle(self::withSession($this->buildRequest('GET', '/api/auth/me'), $token));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeMe(ResponseInterface $response): array
    {
        $me = self::decodeJson($response);
        self::assertIsArray($me);

        return $me;
    }
}
