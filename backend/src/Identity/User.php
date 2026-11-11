<?php

declare(strict_types=1);

namespace App\Identity;

use SensitiveParameter;

/**
 * An account. $passwordHash is null for an account whose credentials were never generated; such an
 * account cannot log in. The hash never leaves the server: the API renders users field by field
 * (see MeAction::present), and debug output leaves it out.
 */
final readonly class User
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
