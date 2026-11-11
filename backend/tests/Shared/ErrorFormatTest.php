<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Http\ApiError;
use App\Shared\Http\ErrorMiddleware;
use App\Shared\Http\JsonResponder;
use App\Tests\Support\ApiTestCase;
use Monolog\Level;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Throwable;

final class ErrorFormatTest extends ApiTestCase
{
    public function test_api_error_renders_code_only(): void
    {
        $response = $this->dispatch(new ApiError(409, 'invalid_transition'));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('{"error":{"code":"invalid_transition","fields":[]}}', (string) $response->getBody());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $decoded = self::decodeJson($response);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['error']);
        self::assertArrayNotHasKey('message', $decoded['error']);
        self::assertArrayNotHasKey('message', $decoded);
    }

    public function test_api_error_renders_field_errors_with_object_params(): void
    {
        $error = new ApiError(422, 'validation_failed', [
            ['field' => 'offer', 'code' => 'required', 'params' => []],
            ['field' => 'title', 'code' => 'too_short', 'params' => ['min' => 2]],
        ]);

        $response = $this->dispatch($error);

        self::assertSame(422, $response->getStatusCode());
        // params is always a JSON object, even when empty, never "[]".
        self::assertSame(
            '{"error":{"code":"validation_failed","fields":['
            . '{"field":"offer","code":"required","params":{}},'
            . '{"field":"title","code":"too_short","params":{"min":2}}]}}',
            (string) $response->getBody(),
        );
    }

    public function test_api_error_params_are_rendered_only_when_present(): void
    {
        $response = $this->dispatch(new ApiError(413, 'file_too_large', params: ['max' => 99000000]));

        self::assertSame(413, $response->getStatusCode());
        self::assertSame(
            '{"error":{"code":"file_too_large","fields":[],"params":{"max":99000000}}}',
            (string) $response->getBody(),
        );
    }

    public function test_api_error_headers_are_sent(): void
    {
        $response = $this->dispatch(new ApiError(429, 'rate_limited', params: ['retry_after' => 300], headers: ['Retry-After' => '300']));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('300', $response->getHeaderLine('Retry-After'));
        self::assertSame(
            '{"error":{"code":"rate_limited","fields":[],"params":{"retry_after":300}}}',
            (string) $response->getBody(),
        );
    }

    public function test_rendered_errors_match_the_error_schema_in_the_spec(): void
    {
        $this->assertMatchesSchema('Error', $this->dispatch(new ApiError(409, 'invalid_transition')));
        $this->assertMatchesSchema('Error', $this->dispatch(new ApiError(
            422,
            'validation_failed',
            [['field' => 'offer', 'code' => 'required', 'params' => []]],
        )));
        $this->assertMatchesSchema('Error', $this->dispatch(new ApiError(429, 'rate_limited', params: ['retry_after' => 3])));
        $this->assertMatchesSchema('Error', $this->dispatch(new RuntimeException('boom')));
    }

    public function test_an_api_error_that_is_a_server_fault_is_logged(): void
    {
        $this->dispatch(new ApiError(503, 'internal_error'));

        self::assertTrue($this->logs->hasErrorRecords());
    }

    public function test_api_error_named_constructors(): void
    {
        self::assertSame(401, ApiError::unauthenticated()->status);
        self::assertSame('unauthenticated', ApiError::unauthenticated()->code);
        self::assertSame(403, ApiError::forbidden()->status);
        self::assertSame('forbidden', ApiError::forbidden()->code);
        self::assertSame(404, ApiError::notFound()->status);
        self::assertSame('not_found', ApiError::notFound()->code);
        self::assertSame([], ApiError::notFound()->fields);
    }

    public function test_unknown_exception_is_500_internal_error_without_trace(): void
    {
        $response = $this->dispatch(new RuntimeException('SQLSTATE[HY000] password=hunter2 in /var/www/backend/src/Secret.php'));

        self::assertSame(500, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertSame('{"error":{"code":"internal_error","fields":[]}}', $body);
        foreach (['hunter2', 'SQLSTATE', 'Secret.php', 'trace', 'RuntimeException', '/var/www'] as $leak) {
            self::assertStringNotContainsString($leak, $body);
        }
    }

    public function test_unknown_exception_is_logged_with_its_trace(): void
    {
        $this->dispatch(new RuntimeException('boom'));

        self::assertTrue($this->logs->hasErrorRecords());
        $record = $this->logs->getRecords()[0];
        self::assertInstanceOf(RuntimeException::class, $record->context['exception']);
    }

    public function test_api_errors_are_not_logged_as_errors(): void
    {
        $this->dispatch(new ApiError(409, 'invalid_transition'));

        self::assertFalse($this->logs->hasErrorRecords());
    }

    public function test_unknown_route_is_404_not_found_json(): void
    {
        $response = $this->request('GET', '/api/does-not-exist');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"error":{"code":"not_found","fields":[]}}', (string) $response->getBody());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function test_wrong_method_on_known_route_is_404_not_found_json(): void
    {
        $response = $this->request('DELETE', '/api/health');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"error":{"code":"not_found","fields":[]}}', (string) $response->getBody());
    }

    public function test_responses_are_never_cached(): void
    {
        $response = $this->request('GET', '/api/health');

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    private function dispatch(Throwable $thrown): ResponseInterface
    {
        $middleware = new ErrorMiddleware(
            new JsonResponder(new ResponseFactory()),
            new Logger('test', [$this->logs->setLevel(Level::Debug)]),
        );
        $handler = new class ($thrown) implements RequestHandlerInterface {
            public function __construct(private readonly Throwable $thrown) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->thrown;
            }
        };

        return $middleware->process((new ServerRequestFactory())->createServerRequest('GET', '/api/x'), $handler);
    }
}
