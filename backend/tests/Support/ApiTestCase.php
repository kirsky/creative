<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\PasswordHasher;
use App\Identity\Role;
use App\Identity\SessionCookie;
use App\Identity\SessionService;
use App\Identity\User;
use App\Identity\UserRepository;
use App\Shared\Clock;
use App\Shared\Config;
use App\Shared\Db\Database;
use App\Shared\MutableClock;
use DateTimeImmutable;
use DateTimeZone;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

use function App\createApp;

/**
 * Base class for tests that drive the whole application through its PSR-15 stack, in process,
 * against the test database and with a clock the test controls.
 *
 * Every endpoint test ends in assertMatchesSpec(): the response must be documented in
 * openapi/openapi.yaml, with the status, content type, headers and body shape it declares.
 */
abstract class ApiTestCase extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    protected App $app;

    protected MutableClock $clock;

    /** What the application logged during the test. */
    protected TestHandler $logs;

    private ?ServerRequestInterface $lastRequest = null;

    private static ?OpenApiValidator $spec = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new MutableClock(new DateTimeImmutable('2026-01-15 09:00:00', new DateTimeZone('UTC')));
        $this->logs = new TestHandler();

        $this->bootApp($this->config);
    }

    /**
     * (Re)builds the application with the given configuration and the test's database, clock and log.
     */
    protected function bootApp(Config $config): void
    {
        $this->app = createApp([
            Config::class => $config,
            Database::class => $this->database,
            Clock::class => $this->clock,
            LoggerInterface::class => new Logger('test', [$this->logs]),
        ]);
    }

    /**
     * Sends a request through the application and returns the response.
     *
     * A non-empty $body is sent as JSON. With $as, the request carries a real session of that user
     * (started for this request through SessionService) and its CSRF token, as a browser would.
     * Extension point for later tasks: whatever every request needs is added in prepareRequest().
     *
     * @param array<string, mixed> $body
     */
    public function request(string $method, string $path, array $body = [], ?User $as = null): ResponseInterface
    {
        $request = $this->buildRequest($method, $path, $body);

        if ($as !== null) {
            [$token, $csrfToken] = $this->startSession($as);
            $request = self::withSession($request, $token, $csrfToken);
        }

        return $this->handle($this->prepareRequest($request));
    }

    /**
     * The request request() would send, without a session.
     *
     * @param array<string, mixed> $body
     */
    protected function buildRequest(string $method, string $path, array $body = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '172.18.0.1']);

        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query);

        if ($body !== []) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $request;
    }

    /**
     * Adds the session cookie (as PHP would parse it from the Cookie header) and, when given, the CSRF header.
     */
    protected static function withSession(ServerRequestInterface $request, string $token, ?string $csrfToken = null): ServerRequestInterface
    {
        $request = $request
            ->withCookieParams([SessionCookie::NAME => $token])
            ->withHeader('Cookie', SessionCookie::NAME . '=' . $token);

        return $csrfToken === null ? $request : $request->withHeader('X-CSRF-Token', $csrfToken);
    }

    /**
     * Starts a real session for $user.
     *
     * @return array{string, string} the session token (the cookie value) and its CSRF token
     */
    protected function startSession(User $user): array
    {
        $sessions = $this->service(SessionService::class);
        $token = $sessions->start($user, '172.18.0.1');
        $session = $sessions->resolveSession($token) ?? throw new RuntimeException('The new session does not resolve.');

        return [$token, $session->csrfToken];
    }

    /**
     * Creates a user. Without $password the account has no password hash, like a seeded account.
     * Passwords given here are test-only and must never appear outside backend/tests. The User
     * returned carries no hash (as from findById); findByUsername() is the lookup that loads it.
     */
    protected function createUser(string $username = 'anna', Role $role = Role::Buyer, ?string $password = null, string $locale = 'en'): User
    {
        $users = $this->service(UserRepository::class);
        $user = $users->create($username, ucfirst($username) . ' Test', $role, $locale);

        if ($password === null) {
            return $user;
        }

        $users->setPasswordHash($user->id, $this->service(PasswordHasher::class)->hash($password));

        return $users->findById($user->id) ?? throw new RuntimeException('The user just created is gone.');
    }

    /**
     * The value of the session cookie a response sets (Set-Cookie is just a header here; the Secure
     * attribute does not stop an in-process test from reading it).
     */
    protected static function sessionTokenFrom(ResponseInterface $response): string
    {
        foreach ($response->getHeader('Set-Cookie') as $line) {
            if (str_starts_with($line, SessionCookie::NAME . '=')) {
                return explode(';', substr($line, strlen(SessionCookie::NAME) + 1), 2)[0];
            }
        }

        throw new RuntimeException('The response sets no session cookie.');
    }

    /**
     * A service from the application's container.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function service(string $id): object
    {
        $service = $this->app->getContainer()->get($id);

        return $service instanceof $id ? $service : throw new RuntimeException(sprintf('The container returned no %s.', $id));
    }

    /**
     * Number of rows in a table of the test database.
     */
    protected function countRows(string $table): int
    {
        $statement = $this->database->pdo()->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * Hook: adjust every request built by request() before it is sent.
     */
    protected function prepareRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request;
    }

    /**
     * Sends a fully built request (for tests that need headers, cookies or files that request() does not offer).
     */
    protected function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        return $this->app->handle($request);
    }

    /**
     * The response to the last request must be documented in openapi/openapi.yaml.
     */
    public function assertMatchesSpec(ResponseInterface $response): void
    {
        $request = $this->lastRequest ?? throw new RuntimeException('assertMatchesSpec() needs a request sent through request() or handle().');

        $errors = self::spec()->validate($request->getMethod(), $request->getUri()->getPath(), $response);

        self::assertSame([], $errors, sprintf(
            "%s %s answered %d, which does not match openapi/openapi.yaml:\n- %s",
            $request->getMethod(),
            $request->getUri()->getPath(),
            $response->getStatusCode(),
            implode("\n- ", $errors),
        ));
    }

    /**
     * The JSON body of the response must satisfy the named schema in components/schemas.
     */
    protected function assertMatchesSchema(string $schema, ResponseInterface $response): void
    {
        $errors = self::spec()->validateSchema($schema, json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR));

        self::assertSame([], $errors, sprintf("The body does not match schema %s:\n- %s", $schema, implode("\n- ", $errors)));
    }

    protected static function decodeJson(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function spec(): OpenApiValidator
    {
        return self::$spec ??= new OpenApiValidator(__DIR__ . '/../../openapi/openapi.yaml');
    }
}
