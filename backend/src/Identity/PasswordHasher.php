<?php

declare(strict_types=1);

namespace App\Identity;

use SensitiveParameter;

/**
 * argon2id password hashing.
 *
 * Parameters: 19 MiB of memory, 2 passes, 1 thread. That is the first configuration OWASP's
 * Password Storage Cheat Sheet recommends for argon2id, chosen for a small VPS: one login costs
 * about 60 ms of one core and 19 MiB of RAM (measured in the php container), so a handful of
 * simultaneous logins cannot exhaust the machine, while an offline attack still pays 19 MiB per guess.
 * Raising them later is safe: needsRehash() lets a successful login upgrade the stored hash.
 */
final class PasswordHasher
{
    public const MEMORY_COST_KIB = 19456;
    public const TIME_COST = 2;
    public const THREADS = 1;

    /**
     * An argon2id hash, with the parameters above, of 32 random bytes that were thrown away when it
     * was made: no password verifies against it. Accounts that do not exist or have no hash are
     * checked against it, so that a login costs the same time whether or not the account can log in.
     * PasswordHasherTest fails when the parameters change and this hash is not regenerated.
     */
    public const DUMMY_HASH = '$argon2id$v=19$m=19456,t=2,p=1$M1U4UTZRSHkvb05veVFWYQ$VO3Yh9x7sKoRj5h3/YAKa8J6rFyYOFDuO5siXvR31xE';

    public function hash(#[SensitiveParameter] string $plain): string
    {
        return password_hash($plain, PASSWORD_ARGON2ID, self::options());
    }

    /**
     * False for a wrong password, a missing hash (null) or a malformed hash; never throws.
     * A missing hash still costs one full argon2id verification.
     */
    public function verify(#[SensitiveParameter] string $plain, #[SensitiveParameter] ?string $hash): bool
    {
        if ($hash === null || password_get_info($hash)['algo'] === null) {
            password_verify($plain, self::DUMMY_HASH);

            return false;
        }

        return password_verify($plain, $hash);
    }

    public function needsRehash(#[SensitiveParameter] string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::options());
    }

    /**
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    private static function options(): array
    {
        return ['memory_cost' => self::MEMORY_COST_KIB, 'time_cost' => self::TIME_COST, 'threads' => self::THREADS];
    }
}
