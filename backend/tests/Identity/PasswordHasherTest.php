<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    private const PASSWORD = 'test-only hasher password';

    public function test_hash_is_argon2id_with_the_documented_parameters(): void
    {
        $hash = (new PasswordHasher())->hash(self::PASSWORD);

        $info = password_get_info($hash);
        self::assertSame(PASSWORD_ARGON2ID, $info['algo']);
        self::assertSame(
            ['memory_cost' => PasswordHasher::MEMORY_COST_KIB, 'time_cost' => PasswordHasher::TIME_COST, 'threads' => PasswordHasher::THREADS],
            $info['options'],
        );
        self::assertStringNotContainsString(self::PASSWORD, $hash);
    }

    public function test_verify_accepts_the_password_and_nothing_else(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash(self::PASSWORD);

        self::assertTrue($hasher->verify(self::PASSWORD, $hash));
        self::assertFalse($hasher->verify(self::PASSWORD . ' ', $hash));
        self::assertFalse($hasher->verify('', $hash));
    }

    public function test_verify_against_a_missing_hash_is_false_and_does_not_throw(): void
    {
        $hasher = new PasswordHasher();

        self::assertFalse($hasher->verify('', null));
        self::assertFalse($hasher->verify('x', null));
        self::assertFalse($hasher->verify(self::PASSWORD, null));
    }

    public function test_verify_against_garbage_is_false(): void
    {
        self::assertFalse((new PasswordHasher())->verify('x', 'not a hash'));
    }

    /**
     * Unknown users and accounts without a hash are checked against a dummy hash, so they cost the
     * same time as a wrong password. That holds only while the dummy uses the current parameters.
     */
    public function test_the_dummy_hash_costs_the_same_as_a_real_one(): void
    {
        $dummy = password_get_info(PasswordHasher::DUMMY_HASH);
        $real = password_get_info((new PasswordHasher())->hash(self::PASSWORD));

        self::assertSame($real, $dummy);
        self::assertFalse((new PasswordHasher())->needsRehash(PasswordHasher::DUMMY_HASH));
    }
}
