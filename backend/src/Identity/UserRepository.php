<?php

declare(strict_types=1);

namespace App\Identity;

use App\Shared\Clock;
use App\Shared\Db\Database;
use PDO;
use RuntimeException;
use SensitiveParameter;

/**
 * The users table. Rows become User objects.
 *
 * Only findByUsername() reads password_hash, because AuthService needs it to check a login; every
 * other query selects NULL in its place, so users kept around (the request actor, lists) carry no hash.
 */
final class UserRepository
{
    /** Every column a User needs, with the hash left out. */
    private const COLUMNS = 'id, username, display_name, role, locale, NULL AS password_hash';

    private const COLUMNS_WITH_HASH = 'id, username, display_name, role, locale, password_hash';

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
    ) {}

    public function findById(int $id): ?User
    {
        return $this->findOne('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?', [$id]);
    }

    /**
     * The one lookup that loads the password hash, for checking a login. Do not keep the result:
     * pass on User::withoutPasswordHash().
     */
    public function findByUsername(string $username): ?User
    {
        return $this->findOne('SELECT ' . self::COLUMNS_WITH_HASH . ' FROM users WHERE username = ?', [$username]);
    }

    /**
     * @return list<User> ordered by id
     */
    public function all(): array
    {
        $statement = $this->database->pdo()->query('SELECT ' . self::COLUMNS . ' FROM users ORDER BY id');

        return $statement === false ? [] : array_values(array_map(self::hydrate(...), $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Creates an account without a password hash: it cannot log in until credentials are generated.
     */
    public function create(string $username, string $displayName, Role $role, string $locale): User
    {
        $pdo = $this->database->pdo();
        $pdo->prepare('INSERT INTO users (username, display_name, role, locale, password_hash, created_at) VALUES (?, ?, ?, ?, NULL, ?)')
            ->execute([$username, $displayName, $role->value, $locale, $this->clock->now()->format('Y-m-d H:i:s')]);

        return new User((int) $pdo->lastInsertId(), $username, $displayName, $role, $locale, null);
    }

    public function setPasswordHash(int $id, #[SensitiveParameter] string $hash): void
    {
        $this->database->pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    public function setLocale(int $id, string $locale): void
    {
        $this->database->pdo()->prepare('UPDATE users SET locale = ? WHERE id = ?')->execute([$locale, $id]);
    }

    /**
     * @param list<int|string> $parameters
     */
    private function findOne(string $sql, array $parameters): ?User
    {
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Builds a User from a row holding at least the COLUMNS (other columns are ignored).
     * The hash is whatever the row's password_hash is: select NULL there unless it is needed.
     */
    public static function hydrate(mixed $row): User
    {
        $hash = is_array($row) ? $row['password_hash'] ?? null : null;

        if (
            !is_array($row)
            || !is_numeric($row['id'] ?? null)
            || !is_string($row['username'] ?? null)
            || !is_string($row['display_name'] ?? null)
            || !is_string($row['role'] ?? null)
            || !is_string($row['locale'] ?? null)
            || !(is_string($hash) || $hash === null)
        ) {
            // Deliberately says nothing about the values: the row holds a password hash.
            throw new RuntimeException('Unexpected shape of a users row.');
        }

        return new User(
            (int) $row['id'],
            $row['username'],
            $row['display_name'],
            Role::from($row['role']),
            $row['locale'],
            $hash,
        );
    }
}
