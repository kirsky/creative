<?php

declare(strict_types=1);

namespace App\Tasks\Http;

use App\Identity\AuthMiddleware;
use App\Shared\Http\JsonResponder;
use App\Tasks\TaskQuery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/tasks/{id}: one task as TaskDetail; 404 not_found for a task the viewer may not see.
 */
final class GetTaskAction
{
    public function __construct(
        private readonly TaskQuery $query,
        private readonly JsonResponder $json,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->json->respond($this->query->detail(AuthMiddleware::actor($request), (int) ($args['id'] ?? 0)));
    }
}
