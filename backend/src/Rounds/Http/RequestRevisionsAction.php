<?php

declare(strict_types=1);

namespace App\Rounds\Http;

use App\Identity\AuthMiddleware;
use App\Rounds\DecisionService;
use App\Shared\Http\JsonResponder;
use App\Tasks\TaskQuery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/tasks/{id}/request-revisions: the buyer who created the task sends the round under review back. Body: {reason}. Answers 200 with the task as TaskDetail.
 */
final class RequestRevisionsAction
{
    public function __construct(
        private readonly DecisionService $service,
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

        $this->service->requestRevisions($actor, $taskId, is_array($body) ? ($body['reason'] ?? null) : null);

        return $this->json->respond($this->query->detail($actor, $taskId));
    }
}
