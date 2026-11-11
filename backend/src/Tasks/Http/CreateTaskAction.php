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
 * POST /api/tasks: a buyer's brief. Answers 201 with the new task as TaskDetail.
 */
final class CreateTaskAction
{
    public function __construct(
        private readonly TaskService $service,
        private readonly TaskQuery $query,
        private readonly JsonResponder $json,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actor = AuthMiddleware::actor($request);
        $body = $request->getParsedBody();

        $task = $this->service->create($actor, is_array($body) ? $body : []);

        return $this->json->respond($this->query->detail($actor, $task->id), 201);
    }
}
