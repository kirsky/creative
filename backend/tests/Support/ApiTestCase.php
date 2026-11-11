<?php

declare(strict_types=1);

namespace App\Tests\Support;

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

        $this->app = createApp([
            Config::class => $this->config,
            Database::class => $this->database,
            Clock::class => $this->clock,
            LoggerInterface::class => new Logger('test', [$this->logs]),
        ]);
    }

    /**
     * Sends a request through the application and returns the response.
     *
     * A non-empty $body is sent as JSON. Extension point for later tasks: whatever every request
     * needs (a session cookie, a CSRF header) is added in prepareRequest().
     *
     * @param array<string, mixed> $body
     */
    public function request(string $method, string $path, array $body = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '172.18.0.1']);

        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query);

        if ($body !== []) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $this->handle($this->prepareRequest($request));
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
