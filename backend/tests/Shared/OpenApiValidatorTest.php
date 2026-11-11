<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\OpenApiValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

/**
 * The OpenAPI response validator is what every later endpoint test leans on, so it is tested itself.
 */
final class OpenApiValidatorTest extends TestCase
{
    private OpenApiValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new OpenApiValidator(__DIR__ . '/fixtures/openapi.fixture.yaml');
    }

    public function test_a_conforming_response_has_no_errors(): void
    {
        self::assertSame([], $this->check('GET', '/api/things', 200, '[{"id":1,"label":null},{"id":2,"label":"x"}]'));
    }

    public function test_a_body_that_breaks_the_schema_is_reported(): void
    {
        self::assertNotSame([], $this->check('GET', '/api/things', 200, '[{"id":"1","label":"x"}]'));
        self::assertNotSame([], $this->check('GET', '/api/things', 200, '[{"id":1,"label":"x","extra":true}]'));
        self::assertNotSame([], $this->check('GET', '/api/things', 200, '{"id":1,"label":"x"}'));
    }

    public function test_a_status_the_spec_does_not_document_is_reported(): void
    {
        self::assertNotSame([], $this->check('GET', '/api/things', 201, '[]'));
        self::assertNotSame([], $this->check('GET', '/api/things/5', 500, '{"error":{"code":"internal_error"}}'));
    }

    public function test_a_range_status_matches_and_resolves_the_response_reference(): void
    {
        self::assertSame([], $this->check('GET', '/api/things', 403, '{"error":{"code":"forbidden"}}'));
        self::assertNotSame([], $this->check('GET', '/api/things', 403, '{"oops":true}'));
    }

    public function test_an_unknown_path_or_method_is_reported(): void
    {
        self::assertNotSame([], $this->check('GET', '/api/nowhere', 200, '{}'));
        self::assertNotSame([], $this->check('PATCH', '/api/things', 200, '[]'));
    }

    public function test_templated_paths_match_and_literal_paths_win(): void
    {
        self::assertSame([], $this->check('GET', '/api/things/7', 200, '{"id":7,"label":"x"}'));
        // /api/things/mine is documented literally, so it is not read as {id}=mine.
        self::assertSame([], $this->check('GET', '/api/things/mine', 200, '{"mine":true}'));
        self::assertNotSame([], $this->check('GET', '/api/things/mine', 200, '{"id":7,"label":"x"}'));
    }

    public function test_a_query_string_is_ignored_when_finding_the_operation(): void
    {
        self::assertSame([], $this->check('GET', '/api/things?limit=5', 200, '[]'));
    }

    public function test_a_response_without_content_must_have_an_empty_body(): void
    {
        self::assertSame([], $this->check('DELETE', '/api/things', 204, ''));
        self::assertNotSame([], $this->check('DELETE', '/api/things', 204, '{"x":1}'));
    }

    public function test_a_required_header_must_be_present(): void
    {
        $body = '{"error":{"code":"rate_limited"}}';

        self::assertNotSame([], $this->check('GET', '/api/things/5', 429, $body));
        self::assertSame([], $this->check('GET', '/api/things/5', 429, $body, ['Retry-After' => '30']));
    }

    public function test_a_content_type_the_spec_does_not_list_is_reported(): void
    {
        self::assertNotSame([], $this->check('GET', '/api/things', 200, '[]', ['Content-Type' => 'text/html']));
    }

    public function test_a_json_content_type_with_parameters_is_accepted(): void
    {
        self::assertSame([], $this->check('GET', '/api/things', 200, '[]', ['Content-Type' => 'application/json; charset=utf-8']));
    }

    public function test_binary_responses_are_checked_for_media_type_only(): void
    {
        self::assertSame([], $this->check('GET', '/api/download', 200, "\x00\x01binary", ['Content-Type' => 'application/octet-stream']));
    }

    public function test_invalid_json_is_reported(): void
    {
        self::assertNotSame([], $this->check('GET', '/api/things', 200, '[{'));
    }

    public function test_a_named_schema_can_be_checked_directly(): void
    {
        self::assertSame([], $this->validator->validateSchema('Thing', ['id' => 1, 'label' => 'x']));
        self::assertNotSame([], $this->validator->validateSchema('Thing', ['id' => 1]));
    }

    public function test_every_reference_in_the_spec_resolves(): void
    {
        self::assertSame([], $this->validator->unresolvedReferences());
        self::assertSame([], (new OpenApiValidator(__DIR__ . '/../../openapi/openapi.yaml'))->unresolvedReferences());
    }

    public function test_only_openapi_3_1_documents_are_accepted(): void
    {
        $path = sys_get_temp_dir() . '/openapi-3-0-' . bin2hex(random_bytes(4)) . '.yaml';
        file_put_contents($path, "openapi: 3.0.3\ninfo: {title: x, version: '1'}\npaths: {}\n");

        try {
            $this->expectException(InvalidArgumentException::class);
            new OpenApiValidator($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private function check(string $method, string $path, int $status, string $body, array $headers = []): array
    {
        $response = (new Response($status))->withBody((new StreamFactory())->createStream($body));
        $headers += ['Content-Type' => 'application/json'];
        if ($body === '') {
            unset($headers['Content-Type']);
        }
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $this->validator->validate($method, $path, $response);
    }
}
