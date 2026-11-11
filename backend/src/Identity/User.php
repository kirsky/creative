<?php

declare(strict_types=1);

namespace App\Identity;

use JsonSerializable;
use SensitiveParameter;

/**
 * An account. $passwordHash is null for an account whose credentials were never generated; such an
 * account cannot log in.
 *
 * The hash is only ever loaded by UserRepository::findByUsername() for AuthService to check a login;
 * every other User (the request actor included) carries null. Even when it is loaded it never leaves
 * the server: json_encode() and Monolog use jsonSerialize(), var_dump() and print_r() use
 * __debugInfo(), and both leave it out. The API renders users field by field (MeAction::present).
 */
final readonly class User implements JsonSerializable
{
    public function __construct(
        public int $id,
        public string $username,
        public string $displayName,
        public Role $role,
        public string $locale,
        #[SensitiveParameter]
        public ?string $passwordHash,
    ) {}

    /**
     * The same user without the password hash, for anything that outlives a login check.
     */
    public function withoutPasswordHash(): self
    {
        return new self($this->id, $this->username, $this->displayName, $this->role, $this->locale, null);
    }

    /**
     * What json_encode() and Monolog's formatters write: everything except the hash.
     *
     * @return array{id: int, username: string, displayName: string, role: string, locale: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'displayName' => $this->displayName,
            'role' => $this->role->value,
            'locale' => $this->locale,
        ];
    }

    /**
     * What var_dump() and print_r() show: everything except the hash.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'displayName' => $this->displayName,
            'role' => $this->role,
            'locale' => $this->locale,
            'passwordHash' => $this->passwordHash === null ? null : '[redacted]',
        ];
    }
}
