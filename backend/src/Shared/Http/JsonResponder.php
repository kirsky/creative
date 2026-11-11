<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Builds every JSON response of the API, so encoding flags and headers are decided in one place.
 */
final class JsonResponder
{
    // Unescaped unicode keeps emoji and Cyrillic readable on the wire; HEX_TAG keeps markup out of the bytes.
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG;

    public function __construct(private readonly ResponseFactoryInterface $responses) {}

    /**
     * @param array<string, string> $headers
     */
    public function respond(mixed $data, int $status = 200, array $headers = []): ResponseInterface
    {
        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            // Responses carry session-bound data and CSRF tokens; nothing may cache them.
            ->withHeader('Cache-Control', 'no-store');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $response->getBody()->write(json_encode($data, self::FLAGS));

        return $response;
    }

    /**
     * The error envelope: {"error":{"code":"…","fields":[{"field","code","params"}]}}.
     * `params` at the top level is only present when there is something to say.
     *
     * @param list<array{field: string, code: string, params: array<string, mixed>}> $fields
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    public function error(int $status, string $code, array $fields = [], array $params = [], array $headers = []): ResponseInterface
    {
        $error = [
            'code' => $code,
            'fields' => array_map(
                // (object) so that empty params encode as {} and not [].
                static fn(array $field): array => [
                    'field' => $field['field'],
                    'code' => $field['code'],
                    'params' => (object) $field['params'],
                ],
                $fields,
            ),
        ];

        if ($params !== []) {
            $error['params'] = (object) $params;
        }

        return $this->respond(['error' => $error], $status, $headers);
    }
}
