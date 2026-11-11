<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\SessionCookie;
use App\Identity\SessionService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/auth/logout: ends this session (only this one) and clears the cookie. 204.
 */
final class LogoutAction
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly SessionCookie $cookie,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $token = SessionCookie::read($request);
        if ($token !== null) {
            $this->sessions->end($token);
        }

        return $this->responses->createResponse(204)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Set-Cookie', $this->cookie->clear());
    }
}
