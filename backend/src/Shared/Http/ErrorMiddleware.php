<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Turns every exception into the API error envelope. Clients get a code and never a message,
 * file path or trace; unexpected failures are logged in full instead.
 */
final class ErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly JsonResponder $json,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ApiError $error) {
            if ($error->status >= 500) {
                $this->log($error, $request);
            }

            return $this->render($request, $error->status, $error->code, $error->fields, $error->params, $error->headers);
        } catch (HttpNotFoundException|HttpMethodNotAllowedException) {
            // Unknown path, or a method the path does not have: the client sees the same answer.
            return $this->render($request, 404, 'not_found');
        } catch (Throwable $error) {
            $this->log($error, $request);

            return $this->render($request, 500, 'internal_error');
        }
    }

    /**
     * Renders the envelope. Rendering must not be able to fail the request: the error may carry
     * client-controlled text that cannot be encoded (invalid UTF-8), and then the client gets the
     * plain 500 envelope instead and the operator gets the reason in the log.
     *
     * @param list<array{field: string, code: string, params: array<string, mixed>}> $fields
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    private function render(ServerRequestInterface $request, int $status, string $code, array $fields = [], array $params = [], array $headers = []): ResponseInterface
    {
        try {
            return $this->json->error($status, $code, $fields, $params, $headers);
        } catch (Throwable $failure) {
            $this->log($failure, $request);

            // Constants only: nothing in this call can fail to encode.
            return $this->json->error(500, 'internal_error');
        }
    }

    private function log(Throwable $error, ServerRequestInterface $request): void
    {
        try {
            $this->logger->error('Unhandled exception: ' . $error->getMessage(), [
                'exception' => $error,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'client_ip' => $request->getAttribute('client_ip'),
            ]);
        } catch (Throwable) {
            // A broken log sink must not turn an error response into a crash.
        }
    }
}
