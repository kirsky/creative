<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Psr7\Response;

/**
 * Terminal PSR-15 handler that remembers the request it received, for testing middleware in isolation.
 */
final class CapturingHandler implements RequestHandlerInterface
{
    private ?ServerRequestInterface $request = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return new Response();
    }

    public function request(): ServerRequestInterface
    {
        return $this->request ?? throw new RuntimeException('The handler was not reached.');
    }
}
