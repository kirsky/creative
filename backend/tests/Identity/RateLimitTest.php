<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\LoginRateLimiter;
use App\Shared\Clock;
use App\Shared\Config;
use App\Shared\Db\Database;
use App\Shared\Http\ApiError;
use App\Tests\Support\ApiTestCase;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function App\createApp;

/**
 * 5 failed logins per 15 minutes per username and per client address, then 429 rate_limited.
 */
final class RateLimitTest extends ApiTestCase
{
    /** Test-only password; it exists in this file and in the test database, nowhere else. */
    private const PASSWORD = 'test-only Лото 🎰 password';

    private const LIMITED = '{"error":{"code":"rate_limited","fields":[],"params":{"retryAfterSeconds":%d}}}';

    public function test_sixth_failed_attempt_for_username_within_15_min_is_429(): void
    {
        $this->createUser('anna', password: self::PASSWORD);

        // Five different clients each get it wrong: the username, not the address, is the culprit.
        for ($i = 1; $i <= 5; ++$i) {
            $response = $this->loginFrom("203.0.113.$i", 'anna', 'wrong');
            self::assertSame(401, $response->getStatusCode(), "attempt $i");
        }

        $sixth = $this->loginFrom('203.0.113.6', 'anna', 'wrong');

        self::assertSame(429, $sixth->getStatusCode());
        self::assertSame(sprintf(self::LIMITED, 900), (string) $sixth->getBody());
        self::assertFalse($sixth->hasHeader('Set-Cookie'));
        $this->assertRetryAfter(900, $sixth);
        $this->assertMatchesSpec($sixth);
    }

    public function test_sixth_failed_attempt_from_same_ip_other_usernames_is_429(): void
    {
        // Existing and unknown names alike: the address is what is being hammered.
        $this->createUser('anna', password: self::PASSWORD);
        $this->createUser('bob', password: self::PASSWORD);

        foreach (['anna', 'bob', 'ghost1', 'ghost2', 'ghost3'] as $username) {
            self::assertSame(401, $this->loginFrom('203.0.113.9', $username, 'wrong')->getStatusCode(), $username);
        }

        $sixth = $this->loginFrom('203.0.113.9', 'a-sixth-name', 'wrong');

        self::assertSame(429, $sixth->getStatusCode());
        self::assertSame(sprintf(self::LIMITED, 900), (string) $sixth->getBody());
        $this->assertRetryAfter(900, $sixth);
        $this->assertMatchesSpec($sixth);

        // Another address is untouched.
        self::assertSame(401, $this->loginFrom('203.0.113.10', 'a-sixth-name', 'wrong')->getStatusCode());
    }

    public function test_correct_password_is_still_blocked_while_limited(): void
    {
        $this->createUser('anna', password: self::PASSWORD);
        $this->failFiveTimes('anna');

        $response = $this->loginFrom('203.0.113.99', 'anna', self::PASSWORD);

        self::assertSame(429, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame(0, $this->countRows('sessions'));
        $this->assertMatchesSpec($response);

        // The same goes for the blocked address, whoever it signs in as.
        $this->createUser('bob', password: self::PASSWORD);
        for ($i = 1; $i <= 5; ++$i) {
            $this->loginFrom('198.51.100.7', "nobody$i", 'wrong');
        }
        self::assertSame(429, $this->loginFrom('198.51.100.7', 'bob', self::PASSWORD)->getStatusCode());
        self::assertSame(0, $this->countRows('sessions'));
    }

    public function test_allowed_again_after_15_minutes(): void
    {
        $this->createUser('anna', password: self::PASSWORD);
        $this->failFiveTimes('anna');
        self::assertSame(429, $this->loginFrom('203.0.113.99', 'anna', self::PASSWORD)->getStatusCode());

        $this->clock->advance('PT15M1S');

        $response = $this->loginFrom('203.0.113.99', 'anna', self::PASSWORD);
        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesSpec($response);

        // The old failures are out of the window for the address as well: a wrong guess is a plain 401.
        self::assertSame(401, $this->loginFrom('203.0.113.1', 'anna', 'wrong')->getStatusCode());
    }

    public function test_blocked_until_the_oldest_failure_is_15_minutes_old(): void
    {
        $this->createUser('anna', password: self::PASSWORD);
        $this->failFiveTimes('anna');

        // One second short of the end of the window: still blocked, one second to wait.
        $this->clock->advance('PT14M59S');
        $almost = $this->loginFrom('203.0.113.99', 'anna', self::PASSWORD);
        self::assertSame(429, $almost->getStatusCode());
        self::assertSame(sprintf(self::LIMITED, 1), (string) $almost->getBody());
        $this->assertRetryAfter(1, $almost);

        // Exactly 15 minutes: the failures have just expired.
        $this->clock->advance('PT1S');
        self::assertSame(200, $this->loginFrom('203.0.113.99', 'anna', self::PASSWORD)->getStatusCode());
    }

    public function test_the_window_is_the_trailing_15_minutes_and_a_blocked_attempt_does_not_extend_it(): void
    {
        $this->createUser('anna', password: self::PASSWORD);
        $this->failFiveTimes('anna');
        self::assertSame(5, $this->countRows('login_attempts'));

        // Blocked attempts, minutes apart: each is told how long is left, and none adds a failure.
        $this->clock->advance('PT5M');
        $blocked = $this->loginFrom('203.0.113.50', 'anna', 'wrong');
        self::assertSame(429, $blocked->getStatusCode());
        $this->assertRetryAfter(600, $blocked);

        $this->clock->advance('PT5M');
        $blocked = $this->loginFrom('203.0.113.51', 'anna', self::PASSWORD);
        self::assertSame(429, $blocked->getStatusCode());
        $this->assertRetryAfter(300, $blocked);

        self::assertSame(5, $this->countRows('login_attempts'));
        self::assertSame(5, $this->countRows('security_events'));

        // So the block ends 15 minutes after the oldest failure, not after the last request.
        $this->clock->advance('PT5M01S');
        self::assertSame(200, $this->loginFrom('203.0.113.52', 'anna', self::PASSWORD)->getStatusCode());
    }

    public function test_failures_age_out_one_at_a_time(): void
    {
        $this->createUser('anna', password: self::PASSWORD);

        for ($i = 1; $i <= 5; ++$i) {
            $this->loginFrom("203.0.113.$i", 'anna', 'wrong');
            $this->clock->advance('PT1M');
        }
        // Failures are at minutes 0..4, now is minute 5. The oldest ends the block at minute 15.
        $blocked = $this->loginFrom('203.0.113.77', 'anna', 'wrong');
        self::assertSame(429, $blocked->getStatusCode());
        $this->assertRetryAfter(600, $blocked);

        $this->clock->advance('PT10M');

        // Minute 15: the failure of minute 0 has left the window, four remain, one guess is allowed.
        self::assertSame(401, $this->loginFrom('203.0.113.78', 'anna', 'wrong')->getStatusCode());
        // That made five again: the oldest is now the minute-1 failure, 60 seconds away.
        $again = $this->loginFrom('203.0.113.79', 'anna', 'wrong');
        self::assertSame(429, $again->getStatusCode());
        $this->assertRetryAfter(60, $again);
    }

    /**
     * @param list<string> $failures usernames that fail
     */
    #[DataProvider('equivalentUsernames')]
    public function test_username_limit_cannot_be_dodged_by_case_space_or_collation_variants(string $target, array $failures): void
    {
        $this->createUser($target, password: self::PASSWORD);

        foreach ($failures as $i => $variant) {
            self::assertSame(401, $this->loginFrom('203.0.113.' . ($i + 1), $variant, 'wrong')->getStatusCode(), $variant);
        }

        // The database finds this account under every one of these spellings (utf8mb4_unicode_ci).
        $blocked = $this->loginFrom('203.0.113.200', $target, self::PASSWORD);
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame(sprintf(self::LIMITED, 900), (string) $blocked->getBody());
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function equivalentUsernames(): array
    {
        return [
            'case and trailing space' => ['anna', ['Anna', 'ANNA', 'anna ', ' ANNA ', 'anna']],
            'accent' => ['anna', ['ánna', 'ANNÁ', 'Anna', 'anna  ', 'aNnA']],
            'full width letters' => ['anna', ['ａｎｎａ', 'ＡＮＮＡ', 'Anna', 'anna', 'annA ']],
            'cyrillic case' => ['анна', ['Анна', 'АННА', 'анна ', 'аннА', ' Анна']],
            'sharp s' => ['strasse', ['STRASSE', 'straße', 'Straße', 'strasse ', 'STRAẞE']],
        ];
    }

    public function test_unrelated_usernames_do_not_share_a_bucket(): void
    {
        $this->createUser('anna', password: self::PASSWORD);
        $this->createUser('annabel', password: self::PASSWORD);
        $this->createUser('bob', password: self::PASSWORD);

        $this->failFiveTimes('anna');

        self::assertSame(200, $this->loginFrom('203.0.113.99', 'annabel', self::PASSWORD)->getStatusCode());
        self::assertSame(200, $this->loginFrom('203.0.113.99', 'bob', self::PASSWORD)->getStatusCode());
    }

    public function test_ipv4_is_bucketed_per_address(): void
    {
        $this->createUser('bob', password: self::PASSWORD);

        foreach (range(1, 5) as $i) {
            self::assertSame(401, $this->loginFrom('203.0.113.5', "user$i", 'wrong')->getStatusCode());
        }

        self::assertSame(429, $this->loginFrom('203.0.113.5', 'bob', self::PASSWORD)->getStatusCode());
        // The neighbouring address is another client.
        self::assertSame(200, $this->loginFrom('203.0.113.6', 'bob', self::PASSWORD)->getStatusCode());
    }

    public function test_ipv6_is_bucketed_per_64_prefix(): void
    {
        $this->createUser('bob', password: self::PASSWORD);

        // Five different addresses of one /64, as one machine with a whole prefix would use.
        $addresses = ['2001:db8:1:2::1', '2001:db8:1:2:aaaa::2', '2001:DB8:1:2:ffff:ffff:ffff:ffff', '2001:db8:0001:0002:0:0:0:4', '2001:db8:1:2:1:2:3:4'];
        foreach ($addresses as $i => $address) {
            self::assertSame(401, $this->loginFrom($address, "user$i", 'wrong')->getStatusCode(), $address);
        }

        // A sixth address of the same /64 is blocked, even signing in as somebody else.
        $blocked = $this->loginFrom('2001:db8:1:2:9::9', 'bob', self::PASSWORD);
        self::assertSame(429, $blocked->getStatusCode());
        $this->assertMatchesSpec($blocked);

        // The next /64 is not.
        self::assertSame(200, $this->loginFrom('2001:db8:1:3::1', 'bob', self::PASSWORD)->getStatusCode());
    }

    public function test_ipv4_mapped_ipv6_address_is_the_ipv4_address(): void
    {
        $limiter = $this->service(LoginRateLimiter::class);

        foreach (range(1, 5) as $i) {
            $limiter->recordFailure("user$i", '::ffff:203.0.113.5');
        }

        $this->assertLimited($limiter, 'bob', '203.0.113.5');
        $this->assertLimited($limiter, 'bob', '::FFFF:cb00:7105');
    }

    public function test_successful_logins_are_not_failures_and_do_not_reset_the_count(): void
    {
        $this->createUser('anna', password: self::PASSWORD);

        for ($i = 1; $i <= 4; ++$i) {
            self::assertSame(401, $this->loginFrom("203.0.113.$i", 'anna', 'wrong')->getStatusCode());
        }

        // A success is not counted as a failure ...
        self::assertSame(200, $this->loginFrom('203.0.113.50', 'anna', self::PASSWORD)->getStatusCode());
        self::assertSame(4, $this->countRows('login_attempts'));
        // ... so the fifth failure is still a plain 401 ...
        self::assertSame(401, $this->loginFrom('203.0.113.5', 'anna', 'wrong')->getStatusCode());
        // ... and it did not reset the count either: that fifth failure closes the window.
        self::assertSame(429, $this->loginFrom('203.0.113.51', 'anna', self::PASSWORD)->getStatusCode());
    }

    public function test_six_good_logins_never_limit_anyone(): void
    {
        $this->createUser('anna', password: self::PASSWORD);

        for ($i = 0; $i < 6; ++$i) {
            self::assertSame(200, $this->loginFrom('203.0.113.1', 'anna', self::PASSWORD)->getStatusCode(), "login $i");
        }
        self::assertSame(0, $this->countRows('login_attempts'));
    }

    public function test_unknown_and_credentialless_accounts_are_limited_like_real_ones(): void
    {
        $this->createUser('seeded');

        foreach (['ghost', 'seeded'] as $username) {
            for ($i = 1; $i <= 5; ++$i) {
                self::assertSame(401, $this->loginFrom("203.0.113.$i", $username, 'wrong')->getStatusCode(), $username);
            }

            $response = $this->loginFrom('203.0.113.99', $username, 'wrong');
            self::assertSame(429, $response->getStatusCode(), $username);
            self::assertSame(sprintf(self::LIMITED, 900), (string) $response->getBody(), $username);
            // Which of the two exists is not something the limiter gives away.
            $this->clock->advance('PT16M');
        }
    }

    public function test_blocked_attempts_write_no_rows(): void
    {
        $this->createUser('anna', password: self::PASSWORD);
        $this->failFiveTimes('anna');

        for ($i = 0; $i < 3; ++$i) {
            $this->loginFrom("203.0.113.1$i", 'anna', 'wrong');
        }

        self::assertSame(5, $this->countRows('login_attempts'));
        self::assertSame(5, $this->countRows('security_events'));
    }

    public function test_rows_older_than_a_day_are_purged_when_a_failure_is_recorded(): void
    {
        $limiter = $this->service(LoginRateLimiter::class);

        $limiter->recordFailure('anna', '203.0.113.1');
        $limiter->recordFailure('bob', '203.0.113.2');
        $this->clock->advance('PT23H59M');
        $limiter->recordFailure('carol', '203.0.113.3');
        self::assertSame(3, $this->countRows('login_attempts'));

        $this->clock->advance('PT2M');
        $limiter->recordFailure('dave', '203.0.113.4');

        // anna and bob are 24h01m old and gone; carol (2 minutes) and dave stay.
        $rows = $this->database->pdo()->query('SELECT username FROM login_attempts ORDER BY id');
        self::assertNotFalse($rows);
        self::assertSame(['carol', 'dave'], $rows->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_the_limiter_reads_the_injected_clock_and_not_the_database_or_system_time(): void
    {
        $limiter = $this->service(LoginRateLimiter::class);

        // A year in the past: if anything asked NOW() or time(), the rows would look ancient and be ignored or purged.
        $this->clock->advance('-1 year');
        for ($i = 1; $i <= 5; ++$i) {
            $limiter->recordFailure('anna', "203.0.113.$i");
        }

        try {
            $limiter->assertAllowed('anna', '203.0.113.99');
            self::fail('Five failures just recorded must limit the username.');
        } catch (ApiError $error) {
            self::assertSame(429, $error->status);
            self::assertSame('rate_limited', $error->code);
            self::assertSame(['retryAfterSeconds' => 900], $error->params);
            self::assertSame(['Retry-After' => '900'], $error->headers);
        }

        self::assertSame(5, $this->countRows('login_attempts'));
    }

    public function test_the_longest_wait_of_the_username_and_the_address_is_reported(): void
    {
        $limiter = $this->service(LoginRateLimiter::class);

        // Minute 0: anna fails from four addresses. Minute 1: from a fifth, which is the one that keeps failing.
        for ($i = 1; $i <= 4; ++$i) {
            $limiter->recordFailure('anna', "203.0.113.2$i");
        }
        $this->clock->advance('PT1M');
        $limiter->recordFailure('anna', '203.0.113.1');
        // That address fails four more times, under other names, at minutes 5 to 8.
        $this->clock->advance('PT4M');
        for ($i = 1; $i <= 4; ++$i) {
            $limiter->recordFailure("other$i", '203.0.113.1');
            $this->clock->advance('PT1M');
        }

        // Minute 9. The name is free at minute 15 (6 minutes), the address at minute 16 (7 minutes): both must be free.
        try {
            $limiter->assertAllowed('anna', '203.0.113.1');
            self::fail('Both buckets are full.');
        } catch (ApiError $error) {
            self::assertSame(['retryAfterSeconds' => 420], $error->params);
            self::assertSame(['Retry-After' => '420'], $error->headers);
        }
    }

    public function test_attempts_for_one_account_are_serialised_so_parallel_guesses_cannot_all_pass_the_check(): void
    {
        // A second connection stands for a second PHP worker in the middle of a login for the same name.
        $worker = new LoginRateLimiter(new Database($this->config), $this->clock);
        $limiter = new LoginRateLimiter($this->database, $this->clock, lockTimeoutSeconds: 0);

        $ran = false;
        $outcome = $worker->serialise('Anna', '203.0.113.1', function () use ($limiter, &$ran): string {
            try {
                // The same account (another spelling), from another address: has to wait its turn.
                $limiter->serialise('anna ', '203.0.113.2', static function () use (&$ran): string {
                    $ran = true;

                    return 'ran';
                });
            } catch (ApiError $error) {
                self::assertSame(429, $error->status);
                self::assertSame('rate_limited', $error->code);
                self::assertSame(['retryAfterSeconds' => 1], $error->params);
                self::assertSame(['Retry-After' => '1'], $error->headers);

                return 'waited';
            }

            return 'overtook';
        });

        self::assertSame('waited', $outcome);
        self::assertFalse($ran);
    }

    public function test_attempts_from_one_address_are_serialised_too(): void
    {
        $worker = new LoginRateLimiter(new Database($this->config), $this->clock);
        $limiter = new LoginRateLimiter($this->database, $this->clock, lockTimeoutSeconds: 0);

        $outcome = $worker->serialise('anna', '2001:db8:1:2::1', function () use ($limiter): string {
            try {
                $limiter->serialise('bob', '2001:db8:1:2:ffff::9', static fn(): string => 'overtook');
            } catch (ApiError) {
                return 'waited';
            }

            return 'overtook';
        });

        self::assertSame('waited', $outcome);
    }

    public function test_unrelated_attempts_do_not_wait_for_each_other(): void
    {
        $worker = new LoginRateLimiter(new Database($this->config), $this->clock);
        $limiter = new LoginRateLimiter($this->database, $this->clock, lockTimeoutSeconds: 0);

        $outcome = $worker->serialise('anna', '203.0.113.1', static fn(): string => $limiter->serialise('bob', '203.0.113.2', static fn(): string => 'parallel'));

        self::assertSame('parallel', $outcome);
    }

    public function test_the_locks_are_released_after_an_attempt_that_returns_and_after_one_that_throws(): void
    {
        $worker = new LoginRateLimiter(new Database($this->config), $this->clock, lockTimeoutSeconds: 0);
        $limiter = new LoginRateLimiter($this->database, $this->clock, lockTimeoutSeconds: 0);

        self::assertSame('first', $worker->serialise('anna', '203.0.113.1', static fn(): string => 'first'));
        self::assertSame('second', $limiter->serialise('anna', '203.0.113.1', static fn(): string => 'second'));

        try {
            $worker->serialise('anna', '203.0.113.1', static function (): never {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame('third', $limiter->serialise('anna', '203.0.113.1', static fn(): string => 'third'));
    }

    public function test_a_login_that_cannot_get_its_turn_is_answered_429_with_retry_after(): void
    {
        $this->createUser('anna', password: self::PASSWORD);
        // The application's limiter gives up at once instead of after its usual wait.
        $this->app = createApp([
            Config::class => $this->config,
            Database::class => $this->database,
            Clock::class => $this->clock,
            LoggerInterface::class => new Logger('test', [$this->logs]),
            LoginRateLimiter::class => new LoginRateLimiter($this->database, $this->clock, lockTimeoutSeconds: 0),
        ]);

        // Another worker is in the middle of a login for the same account.
        $worker = new LoginRateLimiter(new Database($this->config), $this->clock);
        $response = $worker->serialise('anna', '203.0.113.1', fn(): ResponseInterface => $this->loginFrom('203.0.113.2', 'anna', self::PASSWORD));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(sprintf(self::LIMITED, 1), (string) $response->getBody());
        $this->assertRetryAfter(1, $response);
        $this->assertMatchesSpec($response);
        self::assertSame(0, $this->countRows('sessions'));
        self::assertSame(0, $this->countRows('login_attempts'));

        // Once the other worker is done, the same login goes through.
        self::assertSame(200, $this->loginFrom('203.0.113.2', 'anna', self::PASSWORD)->getStatusCode());
    }

    private function failFiveTimes(string $username): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $response = $this->loginFrom("203.0.113.$i", $username, 'wrong');
            self::assertSame(401, $response->getStatusCode(), "failure $i");
        }
    }

    /**
     * POST /api/auth/login as a client with the given address (the container nginx tells the app through X-Forwarded-For).
     */
    private function loginFrom(string $ip, string $username, string $password): ResponseInterface
    {
        return $this->handle(
            $this->buildRequest('POST', '/api/auth/login', ['username' => $username, 'password' => $password])
                ->withHeader('X-Forwarded-For', $ip),
        );
    }

    private function assertLimited(LoginRateLimiter $limiter, string $username, string $ip): void
    {
        try {
            $limiter->assertAllowed($username, $ip);
        } catch (ApiError $error) {
            self::assertSame(429, $error->status);
            self::assertSame('rate_limited', $error->code);

            return;
        }

        self::fail(sprintf('%s from %s should be limited.', $username, $ip));
    }

    private function assertRetryAfter(int $seconds, ResponseInterface $response): void
    {
        self::assertSame((string) $seconds, $response->getHeaderLine('Retry-After'));
        self::assertGreaterThan(0, (int) $response->getHeaderLine('Retry-After'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }
}
