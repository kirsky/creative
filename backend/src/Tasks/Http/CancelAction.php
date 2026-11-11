<?php

declare(strict_types=1);

namespace App\Tasks\Http;

use App\Identity\AuthMiddleware;
use App\Tasks\TaskService;
use App\Shared\Http\JsonResponder;
use App\Tasks\TaskQuery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/tasks/{id}/cancel: the buyer who created the task cancels it. Body: {reason?}. Answers 200 with the task as TaskDetail.
 */
final class CancelAction
{
    public function __construct(
        private readonly TaskService $service,
        private readonly TaskQuery $query,
        private readonly JsonResponder $json,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actor = AuthMiddleware::actor($request);
        $body = $request->getParsedBody();
        $taskId = (int) ($args['id'] ?? 0);

        $this->service->cancel($actor, $taskId, is_array($body) ? ($body['reason'] ?? null) : null);

        return $this->json->respond($this->query->detail($actor, $taskId));
    }
}
