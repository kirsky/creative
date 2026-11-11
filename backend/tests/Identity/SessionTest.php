<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\Role;
use App\Identity\SessionService;
use App\Tests\Support\ApiTestCase;
use PDO;
use Psr\Http\Message\ResponseInterface;

final class SessionTest extends ApiTestCase
{
    private const PASSWORD = 'test-only session password';

    public function test_token_hash_is_stored_not_token(): void
    {
        $user = $this->createUser('anna');

        $token = $this->service(SessionService::class)->start($user, '203.0.113.7');

        // 32 random bytes, hex encoded.
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        $statement = $this->database->pdo()->query('SELECT * FROM sessions');
        self::assertNotFalse($statement);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertIsArray($rows[0]);
        self::assertSame(hash('sha256', $token), $rows[0]['token_hash']);
        self::assertSame('203.0.113.7', $rows[0]['ip']);
        self::assertEquals($user->id, $rows[0]['user_id']);
        foreach ($rows[0] as $column => $value) {
            self::assertStringNotContainsString($token, (string) (is_scalar($value) ? $value : ''), (string) $column);
        }

        self::assertSame($user->id, $this->service(SessionService::class)->resolve($token)?->id);
    }

    public function test_revoke_all_for_user_invalidates_sessions(): void
    {
        $anna = $this->createUser('anna');
        $bob = $this->createUser('bob', Role::Designer);
        $sessions = $this->service(SessionService::class);

        $annaFirst = $sessions->start($anna, '203.0.113.7');
        $annaSecond = $sessions->start($anna, '203.0.113.8');
        $bobs = $sessions->start($bob, '203.0.113.9');

        $sessions->revokeAllFor($anna->id);

        self::assertNull($sessions->resolve($annaFirst));
        self::assertNull($sessions->resolve($annaSecond));
        self::assertSame($bob->id, $sessions->resolve($bobs)?->id);

        $response = $this->me($annaFirst);
        self::assertSame(401, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame(200, $this->me($bobs)->getStatusCode());
    }

    public function test_starting_a_session_does_not_end_the_users_other_sessions(): void
    {
        $this->createUser('bob', Role::Designer, self::PASSWORD);

        $first = self::sessionTokenFrom($this->login());
        $second = self::sessionTokenFrom($this->login());

        self::assertNotSame($first, $second);
        self::assertSame(200, $this->me($first)->getStatusCode());
        self::assertSame(200, $this->me($second)->getStatusCode());
    }

    public function test_each_session_has_its_own_csrf_token(): void
    {
        $user = $this->createUser('anna');

        [, $first] = $this->startSession($user);
        [, $second] = $this->startSession($user);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertNotSame($first, $second);
    }

    public function test_session_expires_after_12_hours_idle(): void
    {
        $user = $this->createUser('anna');
        [$token] = $this->startSession($user);

        $this->clock->advance('PT11H59M59S');
        $sessions = $this->service(SessionService::class);
        self::assertNotNull($sessions->resolveSession($token));

        [$other] = $this->startSession($user);
        $this->clock->advance('PT12H');

        $response = $this->me($other);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('{"error":{"code":"unauthenticated","fields":[]}}', (string) $response->getBody());
        self::assertNull($sessions->resolve($other));
    }

    public function test_activity_slides_the_expiry(): void
    {
        [$token] = $this->startSession($this->createUser('anna'));

        foreach (range(1, 4) as $step) {
            $this->clock->advance('PT11H');
            self::assertSame(200, $this->me($token)->getStatusCode(), sprintf('after %d hours', $step * 11));
        }

        $this->clock->advance('PT12H');
        self::assertSame(401, $this->me($token)->getStatusCode());
    }

    public function test_last_seen_is_written_at_most_once_per_minute(): void
    {
        [$token] = $this->startSession($this->createUser('anna'));
        $started = $this->lastSeen();

        $this->clock->advance('PT59S');
        self::assertSame(200, $this->me($token)->getStatusCode());
        self::assertSame($started, $this->lastSeen());

        $this->clock->advance('PT1S');
        self::assertSame(200, $this->me($token)->getStatusCode());
        self::assertSame('2026-01-15 09:01:00', $this->lastSeen());
    }

    public function test_logout_ends_only_this_session_and_clears_the_cookie(): void
    {
        $user = $this->createUser('anna');
        [$token, $csrf] = $this->startSession($user);
        [$other] = $this->startSession($user);

        $response = $this->handle(self::withSession($this->buildRequest('POST', '/api/auth/logout'), $token, $csrf));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        $this->assertMatchesSpec($response);

        $parts = array_map('trim', explode(';', $response->getHeaderLine('Set-Cookie')));
        self::assertSame('creative_session=', $parts[0]);
        self::assertEqualsCanonicalizing(['Max-Age=0', 'Path=/', 'HttpOnly', 'Secure', 'SameSite=Lax'], array_slice($parts, 1));

        self::assertSame(401, $this->me($token)->getStatusCode());
        self::assertSame(200, $this->me($other)->getStatusCode());
    }

    public function test_a_token_that_was_never_issued_is_unauthenticated(): void
    {
        $this->createUser('anna');

        foreach ([str_repeat('0', 64), 'not-a-token', '', str_repeat('a', 5000)] as $token) {
            $response = $this->me($token);

            self::assertSame(401, $response->getStatusCode());
            $this->assertMatchesSpec($response);
        }
    }

    public function test_the_stored_hash_itself_is_not_a_usable_token(): void
    {
        $user = $this->createUser('anna');
        $token = $this->service(SessionService::class)->start($user, '203.0.113.7');

        self::assertSame(401, $this->me(hash('sha256', $token))->getStatusCode());
    }

    private function me(string $token): ResponseInterface
    {
        return $this->handle(self::withSession($this->buildRequest('GET', '/api/auth/me'), $token));
    }

    private function login(): ResponseInterface
    {
        $response = $this->request('POST', '/api/auth/login', ['username' => 'bob', 'password' => self::PASSWORD]);
        self::assertSame(200, $response->getStatusCode());

        return $response;
    }

    private function lastSeen(): string
    {
        $statement = $this->database->pdo()->query('SELECT last_seen_at FROM sessions');
        self::assertNotFalse($statement);
        $value = $statement->fetchColumn();
        self::assertIsString($value);

        return $value;
    }
}
