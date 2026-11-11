<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\AuthMiddleware;
use App\Identity\AuthService;
use App\Identity\Role;
use App\Identity\SessionService;
use App\Identity\User;
use App\Identity\UserRepository;
use App\Shared\Http\PublicRoute;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The password hash is read for one purpose, checking a login, and goes nowhere else: not into the
 * request's actor, not into JSON, not into a log line, whoever serialises the user later.
 */
final class PasswordHashExposureTest extends ApiTestCase
{
    private const PASSWORD = 'test-only exposure password';

    /** Identifies the stored hash in any output without needing the whole string. */
    private string $hash;

    private User $withHash;

    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->createUser('bob', Role::Designer, self::PASSWORD);
        $this->withHash = $this->service(UserRepository::class)->findByUsername('bob') ?? self::fail('bob is missing');
        $this->hash = (string) $this->withHash->passwordHash;
        self::assertStringStartsWith('$argon2id$', $this->hash);
        self::assertSame($user->id, $this->withHash->id);
    }

    public function test_json_encode_of_a_user_never_contains_the_hash(): void
    {
        $json = json_encode($this->withHash, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($this->hash, $json);
        self::assertStringNotContainsString('argon2', $json);
        self::assertSame(
            ['id' => $this->withHash->id, 'username' => 'bob', 'displayName' => 'Bob Test', 'role' => 'designer', 'locale' => 'en'],
            json_decode($json, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function test_monolog_never_writes_the_hash_of_a_user_in_the_context(): void
    {
        $record = new LogRecord(new DateTimeImmutable(), 'test', Level::Info, 'Something happened.', ['actor' => $this->withHash]);

        foreach ([new JsonFormatter(), new LineFormatter(null, 'c', true, true), new NormalizerFormatter()] as $formatter) {
            $output = $formatter->format($record);
            $text = is_string($output) ? $output : json_encode($output, JSON_THROW_ON_ERROR);

            self::assertStringNotContainsString($this->hash, $text, $formatter::class);
            self::assertStringContainsString('bob', $text, $formatter::class);
        }
    }

    public function test_a_resolved_session_carries_no_hash(): void
    {
        $sessions = $this->service(SessionService::class);
        $token = $sessions->start($this->withHash, '203.0.113.7');

        self::assertNull($sessions->resolve($token)?->passwordHash);
        self::assertSame($this->withHash->id, $sessions->resolveSession($token)?->user->id);
        self::assertNull($sessions->resolveSession($token)->user->passwordHash);
    }

    public function test_the_request_actor_carries_no_hash(): void
    {
        $seen = [];
        $this->app->get('/api/_test/actor', function (ServerRequestInterface $request, ResponseInterface $response) use (&$seen): ResponseInterface {
            $seen[] = AuthMiddleware::actor($request);
            $seen[] = AuthMiddleware::session($request)->user;

            return $response;
        });

        $response = $this->request('GET', '/api/_test/actor', as: $this->withHash);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $seen);
        foreach ($seen as $actor) {
            self::assertInstanceOf(User::class, $actor);
            self::assertSame($this->withHash->id, $actor->id);
            self::assertNull($actor->passwordHash);
        }
        self::assertFalse(PublicRoute::isPublic($this->buildRequest('GET', '/api/_test/actor')));
    }

    public function test_only_find_by_username_reads_the_hash(): void
    {
        $users = $this->service(UserRepository::class);

        self::assertNull($users->findById($this->withHash->id)?->passwordHash);
        foreach ($users->all() as $user) {
            self::assertNull($user->passwordHash);
        }
    }

    public function test_login_still_verifies_and_returns_a_user_without_the_hash(): void
    {
        $user = $this->service(AuthService::class)->login('bob', self::PASSWORD, '203.0.113.7');

        self::assertSame($this->withHash->id, $user->id);
        self::assertNull($user->passwordHash);

        $response = $this->request('POST', '/api/auth/login', ['username' => 'bob', 'password' => self::PASSWORD]);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString($this->hash, (string) $response->getBody());
    }
}
