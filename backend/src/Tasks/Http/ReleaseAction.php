<?php

declare(strict_types=1);

namespace App\Tasks\Http;

use App\Identity\AuthMiddleware;
use App\Shared\Http\JsonResponder;
use App\Tasks\AssignmentService;
use App\Tasks\TaskQuery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/tasks/{id}/release: the assigned designer gives the task back. No body. Answers 200 with the task as TaskDetail.
 */
final class ReleaseAction
{
    public function __construct(
        private readonly AssignmentService $service,
        private readonly TaskQuery $query,
        private readonly JsonResponder $json,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actor = AuthMiddleware::actor($request);

        $task = $this->service->release($actor, (int) ($args['id'] ?? 0));

        return $this->json->respond($this->query->detail($actor, $task->id));
    }
}
