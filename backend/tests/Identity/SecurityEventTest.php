<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\AuthService;
use App\Identity\SecurityEventLog;
use App\Identity\UserRepository;
use App\Shared\Http\ApiError;
use App\Tests\Support\ApiTestCase;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * Who tried to sign in, from where, and how it went: the audit trail of the login. It never
 * holds anything that could be used to sign in.
 */
final class SecurityEventTest extends ApiTestCase
{
    /** Test-only passwords; they exist in this file and in the test database, nowhere else. */
    private const PASSWORD = 'test-only Лото 🎰 password';

    private const ATTEMPTED = 'attempted-<script>-Пароль-🎰-1';

    public function test_events_never_contain_the_password(): void
    {
        $this->createUser('anna', password: self::PASSWORD);

        $this->loginFrom('203.0.113.7', 'anna', self::ATTEMPTED);
        $this->loginFrom('203.0.113.7', 'nobody', self::ATTEMPTED);
        $this->loginFrom('203.0.113.7', 'anna', self::PASSWORD);

        $hash = (string) $this->service(UserRepository::class)->findByUsername('anna')?->passwordHash;
        self::assertStringStartsWith('$argon2id$', $hash);

        $rows = $this->events();
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            // Every column, as the JSON of the whole row: nothing in it can carry a secret.
            $json = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            self::assertFalse(str_contains($json, self::ATTEMPTED), 'the attempted password is in an event');
            self::assertFalse(str_contains($json, self::PASSWORD), 'the real password is in an event');
            self::assertFalse(str_contains($json, $hash), 'the password hash is in an event');
            self::assertStringNotContainsString('argon2', $json);
            self::assertStringNotContainsString('creative_session', $json);
        }
    }

    public function test_failed_login_of_a_known_user_records_username_and_ip_only(): void
    {
        $user = $this->createUser('anna', password: self::PASSWORD);

        $response = $this->loginFrom('203.0.113.7', 'Anna', 'wrong');
        self::assertSame(401, $response->getStatusCode());

        $rows = $this->events();
        self::assertCount(1, $rows);
        self::assertSame('login_failed', $rows[0]['type']);
        self::assertSame($user->id, $rows[0]['user_id']);
        // The username as typed, not as stored.
        self::assertSame(['username' => 'Anna', 'ip' => '203.0.113.7'], $rows[0]['details']);
        self::assertSame('2026-01-15 09:00:00', $rows[0]['created_at']);
    }

    public function test_failed_login_of_an_unknown_user_has_no_user_id(): void
    {
        $this->loginFrom('2001:db8:1:2::77', 'ghost', 'wrong');

        $rows = $this->events();
        self::assertCount(1, $rows);
        self::assertSame('login_failed', $rows[0]['type']);
        self::assertNull($rows[0]['user_id']);
        // The address itself, not its /64 bucket.
        self::assertSame(['username' => 'ghost', 'ip' => '2001:db8:1:2::77'], $rows[0]['details']);
    }

    public function test_account_without_credentials_fails_like_any_other_and_is_recorded_with_its_id(): void
    {
        $seeded = $this->createUser('seeded');

        $this->loginFrom('203.0.113.7', 'seeded', 'x');

        $rows = $this->events();
        self::assertCount(1, $rows);
        self::assertSame('login_failed', $rows[0]['type']);
        self::assertSame($seeded->id, $rows[0]['user_id']);
    }

    public function test_successful_login_records_who_and_from_where(): void
    {
        $user = $this->createUser('anna', password: self::PASSWORD);
        $this->clock->advance('PT3M');

        $response = $this->loginFrom('203.0.113.7', 'ANNA', self::PASSWORD);
        self::assertSame(200, $response->getStatusCode());

        $rows = $this->events();
        self::assertCount(1, $rows);
        self::assertSame('login_succeeded', $rows[0]['type']);
        self::assertSame($user->id, $rows[0]['user_id']);
        self::assertSame(['username' => 'ANNA', 'ip' => '203.0.113.7'], $rows[0]['details']);
        self::assertSame('2026-01-15 09:03:00', $rows[0]['created_at']);
    }

    public function test_a_blocked_attempt_records_no_event(): void
    {
        $this->createUser('anna', password: self::PASSWORD);
        for ($i = 1; $i <= 5; ++$i) {
            $this->loginFrom("203.0.113.$i", 'anna', 'wrong');
        }
        self::assertCount(5, $this->events());

        $blocked = $this->loginFrom('203.0.113.99', 'anna', self::PASSWORD);

        self::assertSame(429, $blocked->getStatusCode());
        self::assertCount(5, $this->events());
    }

    public function test_the_attempted_username_is_cut_to_100_characters_in_the_event(): void
    {
        // The HTTP layer already refuses more than 100; the service is the second line of defence.
        $long = str_repeat('Я🎰', 80);

        try {
            $this->service(AuthService::class)->login($long, 'wrong', '203.0.113.7');
            self::fail('The login must fail.');
        } catch (ApiError $error) {
            self::assertSame(401, $error->status);
        }

        $rows = $this->events();
        self::assertCount(1, $rows);
        self::assertSame(['username' => str_repeat('Я🎰', 50), 'ip' => '203.0.113.7'], $rows[0]['details']);
        self::assertSame(100, mb_strlen($rows[0]['details']['username']));
    }

    public function test_invalid_utf8_in_the_username_cannot_break_recording(): void
    {
        try {
            $this->service(AuthService::class)->login("bad\xFFname", 'wrong', '203.0.113.7');
            self::fail('The login must fail.');
        } catch (ApiError $error) {
            self::assertSame(401, $error->status);
        }

        self::assertCount(1, $this->events());
    }

    public function test_record_stores_any_of_the_known_types_with_free_details(): void
    {
        $user = $this->createUser('anna');
        $log = $this->service(SecurityEventLog::class);

        $log->record('credentials_rotated', null, ['accounts' => 3, 'by' => 'cli']);
        $log->record('login_succeeded', $user->id);

        $rows = $this->events();
        self::assertSame(['credentials_rotated', 'login_succeeded'], array_column($rows, 'type'));
        self::assertNull($rows[0]['user_id']);
        self::assertSame(['accounts' => 3, 'by' => 'cli'], $rows[0]['details']);
        self::assertSame($user->id, $rows[1]['user_id']);
        self::assertSame([], $rows[1]['details']);

        // Without details the column holds an empty JSON object, not an empty list.
        $raw = $this->database->pdo()->query('SELECT details FROM security_events ORDER BY id DESC LIMIT 1');
        self::assertNotFalse($raw);
        self::assertSame('{}', $raw->fetchColumn());
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->service(SecurityEventLog::class)->record('login_succeded', null);
        } finally {
            self::assertSame(0, $this->countRows('security_events'));
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    #[DataProvider('secretLookingDetails')]
    public function test_details_that_look_like_a_secret_are_refused(array $details): void
    {
        try {
            $this->service(SecurityEventLog::class)->record('login_failed', null, $details);
            self::fail('Details with a password, hash or token must be refused.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $this->countRows('security_events'));
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function secretLookingDetails(): array
    {
        return [
            'password' => [['username' => 'anna', 'password' => 'x']],
            'password hash' => [['password_hash' => 'x']],
            'hash' => [['Hash' => 'x']],
            'token' => [['csrfToken' => 'x']],
            'session token nested' => [['request' => ['session_token' => 'x']]],
            'secret' => [['client_secret' => 'x']],
        ];
    }

    public function test_the_table_has_the_documented_columns(): void
    {
        $statement = $this->database->pdo()->query(
            "SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_events' ORDER BY ORDINAL_POSITION",
        );
        self::assertNotFalse($statement);

        self::assertSame(
            ['id' => 'NO', 'type' => 'NO', 'user_id' => 'YES', 'details' => 'NO', 'created_at' => 'NO'],
            $statement->fetchAll(PDO::FETCH_KEY_PAIR),
        );
    }

    private function loginFrom(string $ip, string $username, string $password): ResponseInterface
    {
        return $this->handle(
            $this->buildRequest('POST', '/api/auth/login', ['username' => $username, 'password' => $password])
                ->withHeader('X-Forwarded-For', $ip),
        );
    }

    /**
     * @return list<array{id: int, type: string, user_id: int|null, details: array<array-key, mixed>, created_at: string}>
     */
    private function events(): array
    {
        $statement = $this->database->pdo()->query('SELECT id, type, user_id, details, created_at FROM security_events ORDER BY id');
        self::assertNotFalse($statement);

        $events = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            self::assertIsString($row['details']);
            self::assertIsString($row['type']);
            self::assertIsString($row['created_at']);
            $details = json_decode($row['details'], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($details);

            $events[] = [
                'id' => (int) (is_scalar($row['id']) ? $row['id'] : 0),
                'type' => $row['type'],
                'user_id' => $row['user_id'] === null ? null : (int) (is_scalar($row['user_id']) ? $row['user_id'] : 0),
                'details' => $details,
                'created_at' => $row['created_at'],
            ];
        }

        return $events;
    }
}
