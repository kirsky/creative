<?php

declare(strict_types=1);

namespace App\Identity;

use App\Shared\Http\ApiError;
use App\Shared\Http\PublicRoute;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Every protected request with a method other than GET, HEAD or OPTIONS must carry the session's
 * CSRF token in X-CSRF-Token, compared in constant time; otherwise 403 forbidden.
 *
 * Public routes are exempt: login has no session yet. Runs inside AuthMiddleware; if a protected
 * request somehow arrives without a session, it is refused rather than let through.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public const HEADER = 'X-CSRF-Token';

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true) || PublicRoute::isPublic($request)) {
            return $handler->handle($request);
        }

        $session = $request->getAttribute(AuthMiddleware::SESSION);
        $sent = $request->getHeader(self::HEADER);

        if (!$session instanceof Session || count($sent) !== 1 || !hash_equals($session->csrfToken, $sent[0])) {
            throw ApiError::forbidden();
        }

        return $handler->handle($request);
    }
}
