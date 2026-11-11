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
use App\Tasks\TaskStatus;
use DateTimeImmutable;
use DateTimeZone;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

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
     * $overrides replace further container definitions by name (a fake collaborator, say).
     *
     * @param array<string, mixed> $overrides
     */
    protected function bootApp(Config $config, array $overrides = []): void
    {
        $this->app = createApp([
            Config::class => $config,
            Database::class => $this->database,
            Clock::class => $this->clock,
            LoggerInterface::class => new Logger('test', [$this->logs]),
            ...$overrides,
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
     * @param array<string, string> $serverParams further $_SERVER entries, for example CONTENT_LENGTH
     */
    protected function buildRequest(string $method, string $path, array $body = [], array $serverParams = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '172.18.0.1', ...$serverParams]);

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
     * Uploads one file the way a browser form does: multipart/form-data with the file in $field.
     * The bytes go through a temporary file of this test, as PHP hands uploads to the application.
     * With $as the request carries that user's session and CSRF token.
     */
    public function upload(string $contents, string $clientName = 'file.bin', ?User $as = null, string $field = 'file', ?string $clientType = null, string $path = '/api/files'): ResponseInterface
    {
        return $this->uploadFiles([$field => $this->uploadedFile($contents, $clientName, $clientType)], $as, $path);
    }

    /**
     * An upload as the application receives it: a file in this test's work directory plus what the
     * client said about it. $size and $error default to the truth (the length of $contents, no error).
     */
    protected function uploadedFile(string $contents, string $clientName = 'file.bin', ?string $clientType = null, ?int $size = null, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $incoming = $this->workDir . '/incoming';
        if (!is_dir($incoming) && !mkdir($incoming, 0700)) {
            throw new RuntimeException('Cannot create the directory for incoming uploads.');
        }

        $file = $incoming . '/' . bin2hex(random_bytes(8));
        if (file_put_contents($file, $contents) !== strlen($contents)) {
            throw new RuntimeException('Cannot write the incoming upload.');
        }

        return new UploadedFile($file, $clientName, $clientType, $size ?? strlen($contents), $error);
    }

    /**
     * Sends a multipart request carrying exactly these uploaded files (a tree, like PHP's $_FILES
     * after normalising). For edge cases upload() cannot express: several files, a PHP upload error.
     *
     * @param array<string, mixed> $files
     * @param array<string, string> $serverParams
     */
    protected function uploadFiles(array $files, ?User $as = null, string $path = '/api/files', array $serverParams = []): ResponseInterface
    {
        $request = $this->buildRequest('POST', $path, serverParams: $serverParams)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=----creativetest')
            ->withUploadedFiles($files);

        if ($as !== null) {
            [$token, $csrfToken] = $this->startSession($as);
            $request = self::withSession($request, $token, $csrfToken);
        }

        return $this->handle($this->prepareRequest($request));
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
     * Inserts a task row directly (the API only ever creates New tasks) with its geo, and returns its id.
     * The deadline is a UTC "Y-m-d H:i:s"; created_at and updated_at are the test clock's now.
     *
     * @param list<string> $geo
     */
    protected function insertTask(
        User $createdBy,
        string $offer = 'Лото Клуб',
        TaskStatus $status = TaskStatus::New,
        ?User $assignee = null,
        string $deadline = '2026-02-01 10:00:00',
        string $priority = 'normal',
        string $creativeType = 'static_image',
        array $geo = ['US'],
    ): int {
        $pdo = $this->database->pdo();
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $pdo->prepare(
            'INSERT INTO tasks (offer, creative_type, quantity, description, priority, deadline_at, created_by, status, assignee_id, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$offer, $creativeType, 'Brief', $priority, $deadline, $createdBy->id, $status->value, $assignee?->id, $now, $now]);
        $id = (int) $pdo->lastInsertId();

        foreach ($geo as $code) {
            $pdo->prepare('INSERT INTO task_geo (task_id, code) VALUES (?, ?)')->execute([$id, $code]);
        }

        return $id;
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
     * The rows a query returns on the test database, column name => value (integers come back as integers).
     *
     * @param list<int|string> $parameters
     * @return list<array<string, mixed>>
     */
    protected function fetchRows(string $sql, array $parameters = []): array
    {
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($parameters);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $named = [];
            foreach (is_array($row) ? $row : [] as $column => $value) {
                $named[(string) $column] = $value;
            }
            $rows[] = $named;
        }

        return $rows;
    }

    /**
     * The first column of the first row a query returns on the test database, null when there is no row.
     *
     * @param list<int|string> $parameters
     */
    protected function fetchValue(string $sql, array $parameters = []): mixed
    {
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($parameters);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * The columns of a table of the test database, in table order: name => [type, nullable ("YES"|"NO")].
     * The display width of integers (int(11)) means nothing and is left out of the type: "int unsigned".
     *
     * @return array<string, array{string, string}>
     */
    protected function columnsOf(string $table): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
        );
        $statement->execute([$table]);

        $columns = [];
        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            if (!is_string($row[0] ?? null) || !is_string($row[1] ?? null) || !is_string($row[2] ?? null)) {
                throw new RuntimeException('Unexpected shape of an information_schema.COLUMNS row.');
            }

            $columns[$row[0]] = [preg_replace('/^(big)?int\(\d+\)/', '$1int', $row[1]) ?? $row[1], $row[2]];
        }

        return $columns;
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

    /**
     * A value of the JSON body of a response, by dotted path ("tasks.0.id"; "" is the whole body).
     * Null when any step is missing.
     */
    protected static function jsonAt(ResponseInterface $response, string $path = ''): mixed
    {
        $value = self::decodeJson($response);
        foreach ($path === '' ? [] : explode('.', $path) as $step) {
            if (!is_array($value) || !array_key_exists($step, $value)) {
                return null;
            }
            $value = $value[$step];
        }

        return $value;
    }

    /**
     * An array of the JSON body of a response, by dotted path; fails the test if it is not one.
     *
     * @return array<array-key, mixed>
     */
    protected static function jsonArray(ResponseInterface $response, string $path = ''): array
    {
        $value = self::jsonAt($response, $path);

        return is_array($value) ? $value : self::fail(sprintf('The body of the response has no array at "%s".', $path));
    }

    private static function spec(): OpenApiValidator
    {
        return self::$spec ??= new OpenApiValidator(__DIR__ . '/../../openapi/openapi.yaml');
    }
}
