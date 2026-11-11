<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\AuthMiddleware;
use App\Identity\User;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/auth/me: the signed-in user and the CSRF token of this session.
 */
final class MeAction
{
    public function __construct(private readonly JsonResponder $json) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $session = AuthMiddleware::session($request);

        return $this->json->respond(self::present($session->user, $session->csrfToken));
    }

    /**
     * The only JSON view of the signed-in user (schema Me). Lists its fields one by one, so the
     * password hash cannot slip into a response.
     *
     * @return array{id: int, username: string, displayName: string, role: string, locale: string, csrfToken: string}
     */
    public static function present(User $user, string $csrfToken): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'displayName' => $user->displayName,
            'role' => $user->role->value,
            'locale' => $user->locale,
            'csrfToken' => $csrfToken,
        ];
    }
}
