<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\Role;
use App\Identity\UserRepository;
use App\Tests\Support\ApiTestCase;

final class UserRepositoryTest extends ApiTestCase
{
    public function test_a_created_user_has_no_password_hash(): void
    {
        $users = $this->service(UserRepository::class);

        $user = $users->create('anna', 'Анна 🎰', Role::Buyer, 'ru');

        self::assertNull($user->passwordHash);
        self::assertSame('Анна 🎰', $user->displayName);
        self::assertEquals($user, $users->findById($user->id));
        self::assertEquals($user, $users->findByUsername('anna'));
        self::assertNull($users->findByUsername('nobody'));
        self::assertNull($users->findById($user->id + 1));
    }

    public function test_all_lists_users_in_id_order(): void
    {
        $users = $this->service(UserRepository::class);
        $first = $users->create('anna', 'Anna', Role::Buyer, 'en');
        $second = $users->create('dina', 'Dina', Role::Designer, 'ru');
        $third = $users->create('max', 'Max', Role::Manager, 'en');

        self::assertEquals([$first, $second, $third], $users->all());
    }

    public function test_set_password_hash_and_locale(): void
    {
        $users = $this->service(UserRepository::class);
        $user = $users->create('anna', 'Anna', Role::Buyer, 'en');

        $users->setPasswordHash($user->id, '$argon2id$v=19$m=19456,t=2,p=1$c29tZXNhbHQ$aGFzaA');
        $users->setLocale($user->id, 'ru');

        $reloaded = $users->findByUsername('anna');
        self::assertSame('$argon2id$v=19$m=19456,t=2,p=1$c29tZXNhbHQ$aGFzaA', $reloaded?->passwordHash);
        self::assertSame('ru', $reloaded->locale);
        // Every other lookup leaves the hash out.
        self::assertNull($users->findById($user->id)?->passwordHash);
    }

    public function test_the_hash_is_not_in_debug_output(): void
    {
        $users = $this->service(UserRepository::class);
        $user = $users->create('anna', 'Anna', Role::Buyer, 'en');
        $users->setPasswordHash($user->id, '$argon2id$v=19$m=19456,t=2,p=1$c29tZXNhbHQ$aGFzaA');

        $loaded = $users->findByUsername('anna');
        self::assertNotNull($loaded?->passwordHash);
        $dump = print_r($loaded, true);

        self::assertStringNotContainsString('aGFzaA', $dump);
        self::assertStringContainsString('anna', $dump);
    }
}
