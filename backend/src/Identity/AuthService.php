<?php

declare(strict_types=1);

namespace App\Identity;

use App\Shared\Http\ApiError;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Checks credentials. Every way of failing (no such user, wrong password, an account without a
 * password hash) gives the same 401 invalid_credentials and costs one argon2id verification, so
 * neither the response nor its timing tells which accounts exist or can log in. The User returned
 * carries no password hash.
 *
 * Around that: the rate limit is checked first, before any password is verified (a limited client
 * gets 429 even with the right password, and a blocked attempt is not counted as a failure), every
 * failure is counted against the username and the client address, and the outcome goes to the
 * security event log as {username, ip}, never the password.
 */
final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly LoggerInterface $logger,
        private readonly LoginRateLimiter $limiter,
        private readonly SecurityEventLog $events,
    ) {}

    /**
     * @throws ApiError 401 invalid_credentials; 429 rate_limited (with Retry-After)
     */
    public function login(string $username, #[SensitiveParameter] string $password, string $ip): User
    {
        // Attempts for one username (or address) take turns, so a burst of parallel guesses cannot all pass the limit check.
        return $this->limiter->serialise($username, $ip, fn(): User => $this->attempt($username, $password, $ip));
    }

    private function attempt(string $username, #[SensitiveParameter] string $password, string $ip): User
    {
        // Before anything is verified: a limited client learns nothing from the password it sends.
        $this->limiter->assertAllowed($username, $ip);

        $user = $this->users->findByUsername($username);

        // Always one verification: against the dummy hash when there is no user or no hash.
        $valid = $this->hasher->verify($password, $user?->passwordHash);

        // The attempted username is request input: stored cut to 100 characters, never the password.
        $details = ['username' => mb_substr($username, 0, 100, 'UTF-8'), 'ip' => $ip];

        if ($user === null || $user->passwordHash === null || !$valid) {
            $this->limiter->recordFailure($username, $ip);
            $this->events->record(SecurityEventLog::LOGIN_FAILED, $user?->id, $details);

            throw new ApiError(401, 'invalid_credentials');
        }

        if ($this->hasher->needsRehash($user->passwordHash)) {
            $this->users->setPasswordHash($user->id, $this->hasher->hash($password));
        }

        $this->events->record(SecurityEventLog::LOGIN_SUCCEEDED, $user->id, $details);

        // No username from the request and nothing secret: who logged in, and from where.
        $this->logger->info('Login succeeded.', ['user_id' => $user->id, 'client_ip' => $ip]);

        // The hash has done its job; nothing downstream gets it.
        return $user->withoutPasswordHash();
    }
}
