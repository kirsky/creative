<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Http\PublicRoute;
use App\Shared\Validation\Validator;
use App\Tests\Support\ApiTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Bytes that are not text must never get past the error envelope: a client controls the keys of a
 * urlencoded body, and the keys end up in the validation error.
 */
final class MalformedInputTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A route that validates its body, the way every write endpoint will. Test-only: it exists in
        // this test's app instance and nowhere else. (A plain closure: Slim cannot bind static ones.)
        // Public, so the test is about body handling and not about sessions.
        PublicRoute::mark($this->app->post('/api/_test/validate', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $body = $request->getParsedBody();
            (new Validator())->validate(
                is_array($body) ? $body : [],
                new Assert\Collection(['offer' => new Assert\NotBlank(message: 'required')]),
            );

            return $response;
        }));
    }

    public function test_an_invalid_utf8_key_in_a_urlencoded_body_still_gets_the_json_envelope(): void
    {
        // "%FF=1" is the key "\xFF": not text, and not something json_encode accepts.
        $response = $this->postForm('offer=x&%FF=1');

        $body = (string) $response->getBody();
        self::assertNotSame('', $body);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertTrue(mb_check_encoding($body, 'UTF-8'));
        self::assertSame(
            '{"error":{"code":"validation_failed","fields":[{"field":"_invalid","code":"unknown_field","params":{}}]}}',
            $body,
        );
        $this->assertMatchesSchema('Error', $response);
    }

    public function test_the_other_problems_in_the_same_request_are_still_reported(): void
    {
        $response = $this->postForm('%FF=1');

        self::assertSame(422, $response->getStatusCode());
        $decoded = self::decodeJson($response);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['error']);
        self::assertSame(
            [
                ['field' => 'offer', 'code' => 'required', 'params' => []],
                ['field' => '_invalid', 'code' => 'unknown_field', 'params' => []],
            ],
            $decoded['error']['fields'],
        );
    }

    public function test_a_valid_utf8_request_is_unaffected(): void
    {
        $response = $this->postForm('offer=' . rawurlencode('🎰 Лото'));

        self::assertSame(200, $response->getStatusCode());
    }

    private function postForm(string $encoded): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/_test/validate', ['REMOTE_ADDR' => '172.18.0.1'])
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody((new StreamFactory())->createStream($encoded));

        return $this->handle($request);
    }
}
