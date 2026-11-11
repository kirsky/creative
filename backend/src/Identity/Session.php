<?php

declare(strict_types=1);

namespace App\Identity;

/**
 * A live session as AuthMiddleware sees it: who it belongs to and the CSRF token that
 * state-changing requests made with it must carry.
 */
final readonly class Session
{
    public function __construct(
        public User $user,
        public string $csrfToken,
    ) {}
}
