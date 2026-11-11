<?php

declare(strict_types=1);

namespace App\Identity;

use App\Shared\Clock;
use App\Shared\Db\Database;
use DateTimeImmutable;
use PDO;
use SensitiveParameter;

/**
 * Database-backed sessions.
 *
 * The client holds an opaque token: 32 bytes from random_bytes(), hex encoded, in the session
 * cookie. The server stores only its SHA-256, so the sessions table cannot be replayed as cookies.
 * A session ends after 12 hours without a request (sliding: every request moves last_seen_at, but
 * the row is written at most once a minute) or when it is ended or revoked. A user may hold any
 * number of sessions; starting one never ends another.
 */
final class SessionService
{
    public const IDLE_TIMEOUT_SECONDS = 12 * 3600;

    /** last_seen_at is only rewritten when it is at least this old, to avoid a write per request. */
    private const TOUCH_INTERVAL_SECONDS = 60;

    private const TOKEN_BYTES = 32;

    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
    ) {}

    /**
     * Starts a session and returns its token, the cookie value. The token is not stored anywhere.
     */
    public function start(User $user, string $ip): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $now = $this->clock->now();

        $this->database->pdo()
            ->prepare('INSERT INTO sessions (token_hash, user_id, csrf_token, ip, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([
                self::hash($token),
                $user->id,
                bin2hex(random_bytes(self::TOKEN_BYTES)),
                $ip,
                $now->format(self::DATETIME),
                $now->format(self::DATETIME),
            ]);

        // Housekeeping: expired sessions are already unusable; this only keeps the table small.
        $this->database->pdo()
            ->prepare('DELETE FROM sessions WHERE last_seen_at <= ?')
            ->execute([$this->idleCutoff($now)->format(self::DATETIME)]);

        return $token;
    }

    /**
     * The user of a live session, or null for an unknown, ended, revoked or expired token.
     */
    public function resolve(#[SensitiveParameter] string $token): ?User
    {
        return $this->resolveSession($token)?->user;
    }

    /**
     * Like resolve(), with the session's CSRF token. Counts as activity: moves the idle expiry.
     */
    public function resolveSession(#[SensitiveParameter] string $token): ?Session
    {
        // Anything that is not a token we could have issued is not worth a query.
        if (preg_match('/^[0-9a-f]{' . (self::TOKEN_BYTES * 2) . '}$/D', $token) !== 1) {
            return null;
        }

        $hash = self::hash($token);
        $statement = $this->database->pdo()->prepare(
            'SELECT s.csrf_token, s.last_seen_at, u.id, u.username, u.display_name, u.role, u.locale, u.password_hash
             FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ?',
        );
        $statement->execute([$hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || !is_string($row['csrf_token'] ?? null) || !is_string($row['last_seen_at'] ?? null)) {
            return null;
        }

        $now = $this->clock->now();
        $lastSeen = new DateTimeImmutable($row['last_seen_at'] . ' UTC');

        if ($lastSeen <= $this->idleCutoff($now)) {
            $this->endByHash($hash);

            return null;
        }

        if ($now->getTimestamp() - $lastSeen->getTimestamp() >= self::TOUCH_INTERVAL_SECONDS) {
            $this->database->pdo()
                ->prepare('UPDATE sessions SET last_seen_at = ? WHERE token_hash = ?')
                ->execute([$now->format(self::DATETIME), $hash]);
        }

        return new Session(UserRepository::hydrate($row), $row['csrf_token']);
    }

    public function end(#[SensitiveParameter] string $token): void
    {
        $this->endByHash(self::hash($token));
    }

    /**
     * Ends every session of the user, wherever it was started.
     */
    public function revokeAllFor(int $userId): void
    {
        $this->database->pdo()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);
    }

    private function endByHash(string $hash): void
    {
        $this->database->pdo()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([$hash]);
    }

    /**
     * A session last seen at or before this moment has expired.
     */
    private function idleCutoff(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('-' . self::IDLE_TIMEOUT_SECONDS . ' seconds');
    }

    private static function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }
}
