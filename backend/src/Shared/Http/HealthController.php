<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Liveness check for the proxy and for monitoring. Public, and says nothing about the system.
 */
final class HealthController
{
    public function __construct(private readonly JsonResponder $json) {}

    public function __invoke(): ResponseInterface
    {
        return $this->json->respond(['status' => 'ok']);
    }
}
