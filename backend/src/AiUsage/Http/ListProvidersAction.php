<?php

declare(strict_types=1);

namespace App\AiUsage\Http;

use App\AiUsage\AiProviderRepository;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/ai-providers: the managed list of AI providers, sorted by name, for any signed-in role.
 */
final class ListProvidersAction
{
    public function __construct(
        private readonly AiProviderRepository $providers,
        private readonly JsonResponder $json,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json->respond(['providers' => $this->providers->all()]);
    }
}
