<?php

declare(strict_types=1);

namespace App\Rounds\Http;

use App\Identity\AuthMiddleware;
use App\Rounds\RoundService;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/tasks/{id}/rounds: the assigned designer submits a round of deliverables. Answers 201
 * with {"number": N}, the number of the new round.
 */
final class SubmitRoundAction
{
    public function __construct(
        private readonly RoundService $service,
        private readonly JsonResponder $json,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = $request->getParsedBody();

        $number = $this->service->submit(AuthMiddleware::actor($request), (int) ($args['id'] ?? 0), is_array($body) ? $body : []);

        return $this->json->respond(['number' => $number], 201);
    }
}
