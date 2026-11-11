<?php

declare(strict_types=1);

namespace App\Identity;

use App\Shared\Http\ApiError;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Checks credentials. Every way of failing (no such user, wrong password, an account without a
 * password hash) gives the same 401 invalid_credentials and costs one argon2id verification, so
 * neither the response nor its timing tells which accounts exist or can log in.
 */
final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws ApiError 401 invalid_credentials
     */
    public function login(string $username, #[SensitiveParameter] string $password, string $ip): User
    {
        $user = $this->users->findByUsername($username);

        // Always one verification: against the dummy hash when there is no user or no hash.
        $valid = $this->hasher->verify($password, $user?->passwordHash);

        if ($user === null || $user->passwordHash === null || !$valid) {
            throw new ApiError(401, 'invalid_credentials');
        }

        if ($this->hasher->needsRehash($user->passwordHash)) {
            $this->users->setPasswordHash($user->id, $this->hasher->hash($password));
        }

        // No username from the request and nothing secret: who logged in, and from where.
        $this->logger->info('Login succeeded.', ['user_id' => $user->id, 'client_ip' => $ip]);

        return $user;
    }
}
