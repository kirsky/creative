<?php

declare(strict_types=1);

namespace App\Identity;

use App\Shared\Clock;
use App\Shared\Db\Database;
use App\Shared\Http\ApiError;
use DateTimeImmutable;
use RuntimeException;

/**
 * Slows down password guessing: 5 failed logins per 15 minutes per username and per client address.
 *
 * Once 5 failures lie inside the trailing 15 minutes for the username OR for the address, the next
 * attempt is answered 429 rate_limited, with the seconds until the limit lifts (params and the
 * Retry-After header), whatever password it carries. A blocked attempt records nothing, so being
 * blocked never lengthens the block; a successful login is not a failure and resets nothing.
 *
 * Buckets:
 *  - username: trimmed and case-folded here; the login_attempts column has the collation of
 *    users.username (utf8mb4_unicode_ci), so the database also equates the spellings it equates
 *    when it looks the account up (accents, full-width letters, trailing spaces), and no spelling
 *    of an account is a way round its limit. Names of accounts that do not exist count too, so
 *    the limiter says nothing about which accounts exist.
 *  - address: an IPv4 address as it is, an IPv6 address by its /64 prefix (one subscriber, or one
 *    machine, owns a whole /64), an IPv4-mapped IPv6 address as the IPv4 address.
 *
 * The state is the login_attempts table and the injected Clock; nothing is read from the system
 * clock or NOW(). Rows older than 24 hours are deleted whenever a failure is recorded.
 *
 * Checking and recording are two steps around a slow password check, so concurrent attempts could
 * all pass the check before any of them records. serialise() closes that: attempts for the same
 * username, or the same address bucket, run one at a time (MariaDB named locks, which the server
 * releases if the connection dies).
 */
final class LoginRateLimiter
{
    public const MAX_FAILURES = 5;

    public const WINDOW_SECONDS = 900;

    /** Failures are kept this long; far more than the window, the rest is housekeeping. */
    public const RETENTION_SECONDS = 86400;

    /** Width of the username column. */
    private const USERNAME_MAX_CHARS = 100;

    /** Bucket of an address that is not an IP address at all (cannot happen behind TrustedProxyMiddleware). */
    private const UNKNOWN_ADDRESS = 'unknown';

    private const DATETIME = 'Y-m-d H:i:s';

    /**
     * @param int $lockTimeoutSeconds how long serialise() waits for another attempt for the same
     *                                username or address before answering 429 (0: do not wait)
     */
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly int $lockTimeoutSeconds = 5,
    ) {}

    /**
     * @throws ApiError 429 rate_limited, params.retryAfterSeconds (>= 1), header Retry-After
     */
    public function assertAllowed(string $username, string $ip): void
    {
        $now = $this->clock->now();

        $wait = max(
            $this->secondsUntilAllowed('username', self::usernameKey($username), $now),
            $this->secondsUntilAllowed('ip', self::addressKey($ip), $now),
        );

        if ($wait > 0) {
            throw self::limited($wait);
        }
    }

    public function recordFailure(string $username, string $ip): void
    {
        $now = $this->clock->now();
        $pdo = $this->database->pdo();

        $pdo->prepare('INSERT INTO login_attempts (username, ip, attempted_at) VALUES (?, ?, ?)')
            ->execute([self::usernameKey($username), self::addressKey($ip), $now->format(self::DATETIME)]);

        // Housekeeping on the way: expired failures are already ignored; this only keeps the table small.
        $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
            ->execute([$now->modify('-' . self::RETENTION_SECONDS . ' seconds')->format(self::DATETIME)]);
    }

    /**
     * Runs $attempt (the check, the password verification and the recording) while no other attempt
     * for the same username or the same address bucket is running. Waits up to the lock timeout,
     * then answers 429 with a one second retry.
     *
     * @template T
     * @param callable(): T $attempt
     * @return T
     * @throws ApiError 429 rate_limited when the turn does not come in time
     */
    public function serialise(string $username, string $ip, callable $attempt): mixed
    {
        // One fixed order for everybody, so two attempts that need both locks cannot wait for each other.
        $names = [
            'creative.login.user.' . sha1(self::usernameKey($username)),
            'creative.login.ip.' . sha1(self::addressKey($ip)),
        ];
        sort($names);

        $held = [];

        try {
            foreach ($names as $name) {
                $this->acquire($name);
                $held[] = $name;
            }

            return $attempt();
        } finally {
            foreach (array_reverse($held) as $name) {
                $this->database->pdo()->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
            }
        }
    }

    /**
     * 0 when fewer than 5 failures of the bucket lie in the trailing window. Otherwise the seconds
     * until the 5th newest one expires, which is when the bucket drops below 5 again (with exactly
     * 5 failures that is the oldest).
     *
     * @param 'ip'|'username' $column
     */
    private function secondsUntilAllowed(string $column, string $key, DateTimeImmutable $now): int
    {
        $cutoff = $now->modify('-' . self::WINDOW_SECONDS . ' seconds');

        // A failure counts while attempted_at is after the cutoff, so it expires exactly WINDOW_SECONDS after it happened.
        $statement = $this->database->pdo()->prepare(
            'SELECT attempted_at FROM login_attempts WHERE ' . $column . ' = ? AND attempted_at > ?
             ORDER BY attempted_at DESC LIMIT 1 OFFSET ' . (self::MAX_FAILURES - 1),
        );
        $statement->execute([$key, $cutoff->format(self::DATETIME)]);
        $attemptedAt = $statement->fetchColumn();

        if (!is_string($attemptedAt)) {
            return 0;
        }

        $expires = (new DateTimeImmutable($attemptedAt . ' UTC'))->getTimestamp() + self::WINDOW_SECONDS;

        // The failure is inside the window, so this is at least 1; max() only guards the type.
        return max(1, $expires - $now->getTimestamp());
    }

    private function acquire(string $name): void
    {
        $statement = $this->database->pdo()->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([$name, $this->lockTimeoutSeconds]);
        $acquired = $statement->fetchColumn();

        if ($acquired === false || $acquired === null) {
            throw new RuntimeException('The database could not take the login lock.');
        }

        if ((int) $acquired !== 1) {
            // Somebody else is mid-way through a login for the same username or address.
            throw self::limited(1);
        }
    }

    private static function limited(int $seconds): ApiError
    {
        return new ApiError(429, 'rate_limited', params: ['retryAfterSeconds' => $seconds], headers: ['Retry-After' => (string) $seconds]);
    }

    /**
     * The username as the limiter buckets it: trimmed and case-folded (full Unicode folding, so
     * "STRASSE" and "Straße" agree). Spelling differences that only the database's collation
     * knows about are left to the column's collation.
     */
    private static function usernameKey(string $username): string
    {
        // mb_scrub: anything that is not valid UTF-8 would not fit the column.
        $folded = mb_convert_case(trim(mb_scrub($username, 'UTF-8')), MB_CASE_FOLD, 'UTF-8');

        return mb_substr($folded, 0, self::USERNAME_MAX_CHARS, 'UTF-8');
    }

    /**
     * IPv4: the address. IPv6: the /64 prefix as "2001:db8:1:2::/64". IPv4-mapped IPv6: the IPv4 address.
     */
    private static function addressKey(string $ip): string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);

        if ($packed === false) {
            return self::UNKNOWN_ADDRESS;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }

        if (strlen($packed) === 4) {
            return (string) inet_ntop($packed);
        }

        return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
