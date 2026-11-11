<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\AuthService;
use App\Identity\Cli\RotateCredentialsCommand;
use App\Identity\PasswordHasher;
use App\Identity\Role;
use App\Identity\SecurityEventLog;
use App\Identity\SessionService;
use App\Identity\User;
use App\Identity\UserRepository;
use App\Shared\Clock;
use App\Shared\DemoGuard;
use App\Shared\Http\ApiError;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Monolog\Formatter\LineFormatter;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * demo:credentials:rotate is the only way the demo accounts get a password, so these tests pin down
 * what it must never do (run outside the demo CLI, leave a plaintext password anywhere but stdout,
 * half-finish) as much as what it does.
 */
final class RotateCredentialsTest extends ApiTestCase
{
    /** Not in id order and not alphabetical, so the output order proves it follows the id. */
    private const USERNAMES = ['farid', 'anna', 'elena', 'boris', 'dmitri', 'clara'];

    /** Test-only; the accounts that "already have credentials" start with it. */
    private const OLD_PASSWORD = 'test-only old password';

    private const PASSWORD_PATTERN = '/^[abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ2-9]{20,}$/D';

    public function test_refuses_when_app_env_is_not_demo(): void
    {
        $this->createAccounts();
        $before = $this->snapshot();

        foreach (['production', 'test', '', 'Demo'] as $appEnv) {
            [$exit, $stdout, $stderr] = $this->rotate($this->command(appEnv: $appEnv));

            self::assertNotSame(0, $exit, sprintf('APP_ENV "%s" must be refused', $appEnv));
            self::assertSame('', $stdout);
            // One line, on stderr, saying why.
            self::assertMatchesRegularExpression('/\Aerror: [^\n]*APP_ENV=demo[^\n]*\n\z/', $stderr);
            self::assertSame($before, $this->snapshot(), sprintf('APP_ENV "%s" changed something', $appEnv));
        }
    }

    public function test_refuses_non_cli_sapi(): void
    {
        try {
            DemoGuard::assertDemoCli('demo', 'fpm-fcgi');
            self::fail('The guard must refuse a web SAPI even with APP_ENV=demo.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->createAccounts();
        $before = $this->snapshot();

        foreach (['fpm-fcgi', 'cli-server', 'phpdbg'] as $sapi) {
            [$exit, $stdout, $stderr] = $this->rotate($this->command(sapi: $sapi));

            self::assertNotSame(0, $exit, sprintf('SAPI "%s" must be refused', $sapi));
            self::assertSame('', $stdout);
            self::assertMatchesRegularExpression('/\Aerror: [^\n]*\n\z/', $stderr);
            self::assertSame($before, $this->snapshot(), sprintf('SAPI "%s" changed something', $sapi));
        }
    }

    public function test_every_account_gets_independent_password_of_min_length_20(): void
    {
        $this->createAccounts();
        $users = $this->service(UserRepository::class);
        $hasher = $this->service(PasswordHasher::class);

        [$exit, $stdout, $stderr] = $this->rotate();

        self::assertSame(0, $exit);
        $credentials = self::credentials($stdout);

        // One line per account, in the order of the account ids.
        self::assertSame(self::USERNAMES, array_column($credentials, 0));
        self::assertCount(6, array_unique(array_column($credentials, 1)), 'the passwords must be six different ones');

        foreach ($credentials as $index => [$username, $password]) {
            self::assertGreaterThanOrEqual(20, strlen($password));
            self::assertMatchesRegularExpression(self::PASSWORD_PATTERN, $password);

            $hash = $users->findByUsername($username)?->passwordHash;
            self::assertNotNull($hash);
            self::assertStringStartsWith('$argon2id$', $hash);
            self::assertTrue($hasher->verify($password, $hash), sprintf('%s: the printed password does not verify', $username));

            // And it is that account's own: the neighbour's hash does not take it.
            $neighbour = $users->findByUsername($credentials[($index + 1) % 6][0])?->passwordHash;
            self::assertFalse($hasher->verify($password, $neighbour));
        }

        // The warning is for people, on stderr; stdout is data only.
        self::assertStringContainsString('shown once', $stderr);
        self::assertStringContainsString('cannot be recovered', $stderr);
    }

    #[DataProvider('verbosities')]
    public function test_stdout_is_plain_lines_whatever_the_output_options(int $verbosity): void
    {
        $this->createAccounts();

        // --ansi would colour a formatted line and --quiet or --silent would drop it: the credentials
        // are the command's result, and losing them after the passwords were replaced would lock everyone out.
        [$exit, $stdout] = $this->rotate(null, ['decorated' => true, 'verbosity' => $verbosity]);

        self::assertSame(0, $exit);
        self::assertStringNotContainsString("\e", $stdout);
        self::assertSame(self::USERNAMES, array_column(self::credentials($stdout), 0));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function verbosities(): array
    {
        return [
            'silent' => [OutputInterface::VERBOSITY_SILENT],
            'quiet' => [OutputInterface::VERBOSITY_QUIET],
            'normal' => [OutputInterface::VERBOSITY_NORMAL],
            'verbose' => [OutputInterface::VERBOSITY_VERBOSE],
            'debug' => [OutputInterface::VERBOSITY_DEBUG],
        ];
    }

    public function test_all_existing_sessions_revoked(): void
    {
        $users = $this->createAccounts();
        $sessions = $this->service(SessionService::class);
        $tokens = [];
        foreach ($users as $user) {
            $tokens[] = $sessions->start($user, '203.0.113.7');
            $tokens[] = $sessions->start($user, '203.0.113.8');
        }
        self::assertSame(12, $this->countRows('sessions'));

        [$exit] = $this->rotate();

        self::assertSame(0, $exit);
        self::assertSame(0, $this->countRows('sessions'));
        foreach ($tokens as $token) {
            self::assertNull($sessions->resolve($token));
        }
    }

    public function test_plaintext_not_in_db_security_events_or_monolog_output(): void
    {
        $this->createAccounts();

        [$exit, $stdout, $stderr] = $this->rotate();
        self::assertSame(0, $exit);
        $credentials = self::credentials($stdout);

        // Use the passwords the way the application does, so that its code paths log and record
        // what they know: a login through the service and one through the whole HTTP stack.
        foreach ($credentials as [$username, $password]) {
            $this->service(AuthService::class)->login($username, $password, '172.18.0.1');
        }
        [$username, $password] = $credentials[0];
        self::assertSame(200, $this->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password])->getStatusCode());

        $log = $this->logText();
        $dump = $this->databaseDump();

        // The scans are not vacuous: they hold what they should.
        self::assertStringContainsString('Login succeeded.', $log);
        self::assertStringContainsString('credentials_rotated', $dump);
        self::assertStringContainsString('login_succeeded', $dump);
        self::assertStringContainsString('"username":"anna"', $dump);
        self::assertStringContainsString('$argon2id$', $dump);

        foreach ($credentials as [$username, $password]) {
            self::assertFalse(str_contains($dump, $password), sprintf('the password of %s is in the database', $username));
            self::assertFalse(str_contains($log, $password), sprintf('the password of %s is in the log', $username));
            self::assertFalse(str_contains($stderr, $password), sprintf('the password of %s is on stderr', $username));
            self::assertFalse(
                str_contains(json_encode(getenv(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $password),
                sprintf('the password of %s is in the environment', $username),
            );
        }
    }

    public function test_first_run_enables_login_for_accounts_that_had_null_hash(): void
    {
        // The state the demo seed leaves: accounts that exist and cannot sign in.
        foreach (self::USERNAMES as $username) {
            $this->createUser($username);
        }
        $auth = $this->service(AuthService::class);

        try {
            $auth->login('anna', 'whatever-it-was-before', '172.18.0.1');
            self::fail('An account without a hash must not log in.');
        } catch (ApiError $error) {
            self::assertSame('invalid_credentials', $error->code);
        }

        [$exit, $stdout] = $this->rotate();
        self::assertSame(0, $exit);

        // The command's own stdout, line by line, is all it takes to sign in.
        foreach (self::credentials($stdout) as [$username, $password]) {
            $user = $auth->login($username, $password, '172.18.0.1');

            self::assertSame($username, $user->username);
            self::assertNull($user->passwordHash);
        }
    }

    public function test_a_second_run_replaces_the_first_runs_passwords(): void
    {
        $this->createAccounts();
        $auth = $this->service(AuthService::class);

        $first = self::credentials($this->rotate()[1]);
        $second = self::credentials($this->rotate()[1]);

        self::assertNotSame(array_column($first, 1), array_column($second, 1));
        self::assertSame(array_column($first, 0), array_column($second, 0));

        try {
            $auth->login($first[0][0], $first[0][1], '172.18.0.1');
            self::fail('A password from a previous run must stop working.');
        } catch (ApiError $error) {
            self::assertSame('invalid_credentials', $error->code);
        }
        self::assertSame($second[0][0], $auth->login($second[0][0], $second[0][1], '172.18.0.1')->username);
    }

    public function test_records_one_credentials_rotated_event_without_secrets(): void
    {
        $this->createAccounts();

        $this->rotate();

        $statement = $this->database->pdo()->query('SELECT type, user_id, details FROM security_events');
        self::assertNotFalse($statement);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(1, $rows);
        self::assertIsArray($rows[0]);
        self::assertSame('credentials_rotated', $rows[0]['type']);
        self::assertNull($rows[0]['user_id']);
        self::assertIsString($rows[0]['details']);
        self::assertSame(['accounts' => 6], json_decode($rows[0]['details'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_without_accounts_it_fails_and_creates_nothing(): void
    {
        [$exit, $stdout, $stderr] = $this->rotate();

        self::assertNotSame(0, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('no accounts exist; run the demo seed first', $stderr);
        self::assertSame(0, $this->countRows('users'));
        self::assertSame(0, $this->countRows('security_events'));
    }

    public function test_when_anything_fails_nothing_is_printed_and_nothing_changes(): void
    {
        $users = $this->createAccounts();
        $sessions = $this->service(SessionService::class);
        $sessions->start($users[0], '203.0.113.7');
        $before = $this->snapshot();

        // Recording the event is the last step, after every hash was replaced and every session
        // revoked; failing there must undo all of it.
        $failing = new class implements Clock {
            public function now(): DateTimeImmutable
            {
                throw new RuntimeException('the clock broke');
            }
        };

        [$exit, $stdout, $stderr] = $this->rotate($this->command(eventClock: $failing));

        self::assertNotSame(0, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('nothing was changed', $stderr);
        self::assertSame($before, $this->snapshot());
        self::assertSame(1, $this->countRows('sessions'));
        self::assertSame(0, $this->countRows('security_events'));
    }

    public function test_a_username_that_would_break_the_line_format_stops_everything(): void
    {
        $this->createAccounts();
        $this->createUser("tab\tbed");
        $before = $this->snapshot();

        [$exit, $stdout, $stderr] = $this->rotate();

        self::assertNotSame(0, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('nothing was changed', $stderr);
        // The message names the account by id; the username itself is not echoed.
        self::assertStringNotContainsString("\t", $stderr);
        self::assertSame($before, $this->snapshot());
    }

    #[DataProvider('appEnvironments')]
    public function test_the_container_builds_the_command_from_config_and_the_php_sapi(string $appEnv, bool $allowed): void
    {
        $this->bootApp($this->config->with(['APP_ENV' => $appEnv]));
        $this->createAccounts();

        [$exit, $stdout] = $this->rotate($this->service(RotateCredentialsCommand::class));

        // The tests run on the command line, so only APP_ENV decides.
        self::assertSame('cli', PHP_SAPI);
        self::assertSame($allowed, $exit === 0);
        self::assertSame($allowed ? 6 : 0, count(array_filter(explode("\n", $stdout))));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function appEnvironments(): array
    {
        return ['demo' => ['demo', true], 'test' => ['test', false], 'production' => ['production', false]];
    }

    public function test_the_console_binary_registers_it_and_it_refuses_outside_demo(): void
    {
        $this->createAccounts();
        $before = $this->snapshot();

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../bin/console', 'demo:credentials:rotate'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            // Nothing but a non-demo environment: the command must not get as far as the database.
            ['APP_ENV' => 'production'],
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertNotSame(0, $exit);
        self::assertSame('', $stdout);
        // "Command not defined" would also exit non-zero: the message tells the two apart.
        self::assertStringContainsString('APP_ENV=demo', (string) $stderr);
        self::assertSame($before, $this->snapshot());
    }

    public function test_no_http_route_exposes_it(): void
    {
        $patterns = [];
        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            $patterns[] = $route->getPattern();
        }

        // The walk covers today's routes and every route a later task adds.
        self::assertContains('/api/health', $patterns);
        foreach ($patterns as $pattern) {
            self::assertStringNotContainsString('rotate', strtolower($pattern), $pattern);
            self::assertStringNotContainsString('credentials', strtolower($pattern), $pattern);
        }
    }

    /**
     * Six accounts. The ones at odd positions have a password and the others, like seeded accounts,
     * have none.
     *
     * @return list<User> in id order
     */
    private function createAccounts(): array
    {
        $roles = [Role::Buyer, Role::Designer, Role::Manager];
        $users = [];

        foreach (self::USERNAMES as $index => $username) {
            $users[] = $this->createUser($username, $roles[$index % 3], $index % 2 === 1 ? self::OLD_PASSWORD : null);
        }

        return $users;
    }

    private function command(string $appEnv = 'demo', string $sapi = 'cli', ?Clock $eventClock = null): RotateCredentialsCommand
    {
        return new RotateCredentialsCommand(
            $this->service(UserRepository::class),
            $this->service(PasswordHasher::class),
            $this->service(SessionService::class),
            $eventClock === null ? $this->service(SecurityEventLog::class) : new SecurityEventLog($this->database, $eventClock),
            $this->database,
            $appEnv,
            $sapi,
        );
    }

    /**
     * Runs the command with stdout and stderr kept apart, as a shell would.
     *
     * @param array<string, mixed> $options CommandTester options
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function rotate(?RotateCredentialsCommand $command = null, array $options = []): array
    {
        $tester = new CommandTester($command ?? $this->command());
        $exit = $tester->execute([], $options + ['capture_stderr_separately' => true]);

        self::assertContains($exit, [Command::SUCCESS, Command::FAILURE, Command::INVALID]);

        return [$exit, $tester->getDisplay(), $tester->getErrorOutput()];
    }

    /**
     * stdout of the command as the setup script reads it: nothing but username<TAB>password lines.
     *
     * @return list<array{string, string}>
     */
    private static function credentials(string $stdout): array
    {
        self::assertNotSame('', $stdout);
        self::assertStringEndsWith("\n", $stdout);

        $credentials = [];
        foreach (explode("\n", substr($stdout, 0, -1)) as $line) {
            $fields = explode("\t", $line);
            self::assertCount(2, $fields, 'every stdout line must be username<TAB>password');
            $credentials[] = [$fields[0], $fields[1]];
        }

        return $credentials;
    }

    /**
     * Everything the command may change: the hashes (by account id) and the sessions.
     *
     * @return array{hashes: array<int, string|null>, sessions: list<string>}
     */
    private function snapshot(): array
    {
        $pdo = $this->database->pdo();
        $users = $pdo->query('SELECT id, password_hash FROM users ORDER BY id');
        $sessions = $pdo->query('SELECT token_hash FROM sessions ORDER BY token_hash');
        self::assertNotFalse($users);
        self::assertNotFalse($sessions);

        $hashes = [];
        foreach ($users->fetchAll(PDO::FETCH_ASSOC) as $row) {
            self::assertIsArray($row);
            self::assertIsNumeric($row['id']);
            self::assertTrue($row['password_hash'] === null || is_string($row['password_hash']));
            $hashes[(int) $row['id']] = $row['password_hash'];
        }

        $tokens = [];
        foreach ($sessions->fetchAll(PDO::FETCH_COLUMN) as $token) {
            self::assertIsString($token);
            $tokens[] = $token;
        }

        return ['hashes' => $hashes, 'sessions' => $tokens];
    }

    /**
     * Every row of every table of the test database, as one string.
     */
    private function databaseDump(): string
    {
        $pdo = $this->database->pdo();
        $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
        self::assertNotFalse($tables);

        $dump = '';
        foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', (string) (is_scalar($table) ? $table : '')) . '`');
            self::assertNotFalse($rows);

            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $dump .= json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            }
        }

        return $dump;
    }

    /**
     * What the application logged, formatted the way the production handler writes it (stack traces included).
     */
    private function logText(): string
    {
        $formatter = new LineFormatter(null, 'c', true, true);
        $formatter->includeStacktraces();

        return implode("\n", array_map($formatter->format(...), $this->logs->getRecords()));
    }
}
