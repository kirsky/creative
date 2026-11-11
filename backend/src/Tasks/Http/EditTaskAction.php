<?php

declare(strict_types=1);

namespace App\Tasks\Http;

use App\Identity\AuthMiddleware;
use App\Shared\Http\JsonResponder;
use App\Tasks\TaskQuery;
use App\Tasks\TaskService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /api/tasks/{id}: a partial edit of the brief by the buyer who created the task. Answers 200
 * with the task as TaskDetail, whether or not anything changed.
 */
final class EditTaskAction
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

        $task = $this->service->editBrief($actor, (int) ($args['id'] ?? 0), is_array($body) ? $body : []);

        return $this->json->respond($this->query->detail($actor, $task->id));
    }
}
