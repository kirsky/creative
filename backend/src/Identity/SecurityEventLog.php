<?php

declare(strict_types=1);

namespace App\Identity;

use App\Shared\Clock;
use App\Shared\Db\Database;
use InvalidArgumentException;

/**
 * The audit trail of sign-ins and credential changes (the security_events table).
 *
 * `details` is a small JSON object about the event. It never holds anything that could be used to
 * sign in: no password, no hash, no token, and never a request body or header. For the login
 * events it is exactly {username, ip}: the username that was typed (cut to 100 characters) and the
 * client address. record() refuses details with a key that names a secret, so that a careless
 * caller fails in tests, not in production.
 */
final class SecurityEventLog
{
    public const LOGIN_SUCCEEDED = 'login_succeeded';

    public const LOGIN_FAILED = 'login_failed';

    public const CREDENTIALS_ROTATED = 'credentials_rotated';

    private const TYPES = [self::LOGIN_SUCCEEDED, self::LOGIN_FAILED, self::CREDENTIALS_ROTATED];

    /** A key containing any of these (any case) is taken to hold a secret. */
    private const SECRET_KEY_PARTS = ['password', 'passwd', 'hash', 'token', 'secret'];

    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array<string, mixed> $details
     * @throws InvalidArgumentException for an unknown type or details that name a secret
     */
    public function record(string $type, ?int $userId, array $details = []): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown security event type "%s".', $type));
        }

        self::assertNoSecretKeys($details);

        $this->database->pdo()
            ->prepare('INSERT INTO security_events (type, user_id, details, created_at) VALUES (?, ?, ?, ?)')
            ->execute([
                $type,
                $userId,
                // (object): an empty array must be stored as {}, not [].
                json_encode((object) $details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                $this->clock->now()->format(self::DATETIME),
            ]);
    }

    /**
     * @param array<array-key, mixed> $details
     */
    private static function assertNoSecretKeys(array $details): void
    {
        foreach ($details as $key => $value) {
            $name = strtolower((string) $key);

            foreach (self::SECRET_KEY_PARTS as $part) {
                if (str_contains($name, $part)) {
                    throw new InvalidArgumentException(sprintf('Security event details must not contain "%s": it looks like a secret.', $key));
                }
            }

            if (is_array($value)) {
                self::assertNoSecretKeys($value);
            }
        }
    }
}
