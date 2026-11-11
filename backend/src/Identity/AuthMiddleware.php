<?php

declare(strict_types=1);

namespace App\Identity;

use App\Shared\Http\ApiError;
use App\Shared\Http\PublicRoute;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Default deny: every routed request needs a live session unless its route is marked with
 * PublicRoute::mark(). Sets the request attributes "actor" (User) and "session" (Session).
 *
 * Runs after routing, so it knows the matched route; unknown paths never get this far (404).
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public const ACTOR = 'actor';
    public const SESSION = 'session';

    public function __construct(private readonly SessionService $sessions) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (PublicRoute::isPublic($request)) {
            return $handler->handle($request);
        }

        $token = SessionCookie::read($request);
        $session = $token === null ? null : $this->sessions->resolveSession($token);

        if ($session === null) {
            throw ApiError::unauthenticated();
        }

        return $handler->handle(
            $request
                ->withAttribute(self::ACTOR, $session->user)
                ->withAttribute(self::SESSION, $session),
        );
    }

    /**
     * The authenticated user of a request that passed this middleware.
     */
    public static function actor(ServerRequestInterface $request): User
    {
        $actor = $request->getAttribute(self::ACTOR);

        return $actor instanceof User ? $actor : throw new LogicException('The request was not authenticated; is the route public?');
    }

    public static function session(ServerRequestInterface $request): Session
    {
        $session = $request->getAttribute(self::SESSION);

        return $session instanceof Session ? $session : throw new LogicException('The request was not authenticated; is the route public?');
    }
}
