<?php

declare(strict_types=1);

namespace App\AiUsage\Http;

use App\AiUsage\AiUsageService;
use App\Identity\AuthMiddleware;
use App\Shared\Http\JsonResponder;
use App\Tasks\TaskQuery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/tasks/{id}/rounds/{n}/ai-usage: the designer who submitted round n replaces its AI usage
 * lines before the buyer decides it. Body: {lines}. Answers 200 with the task as TaskDetail.
 */
final class ReplaceAiUsageAction
{
    public function __construct(
        private readonly AiUsageService $service,
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

        $this->service->replace($actor, $taskId, (int) ($args['n'] ?? 0), is_array($body) ? ($body['lines'] ?? null) : null);

        return $this->json->respond($this->query->detail($actor, $taskId));
    }
}
