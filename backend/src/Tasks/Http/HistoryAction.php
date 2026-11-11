<?php

declare(strict_types=1);

namespace App\Tasks\Http;

use App\History\TaskEvent;
use App\History\TaskEventLog;
use App\Identity\AuthMiddleware;
use App\Shared\Http\JsonResponder;
use App\Tasks\TaskQuery;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/tasks/{id}/history: {events: [{id, type, actor: {id, displayName}, at, payload}]}, oldest
 * first, for anybody who may view the task (404 not_found for everybody else).
 */
final class HistoryAction
{
    public function __construct(
        private readonly TaskQuery $query,
        private readonly TaskEventLog $events,
        private readonly JsonResponder $json,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $task = $this->query->viewable(AuthMiddleware::actor($request), (int) ($args['id'] ?? 0));

        return $this->json->respond(['events' => array_map(self::present(...), $this->events->forTask($task->id))]);
    }

    /**
     * @return array{id: int, type: string, actor: array{id: int, displayName: string}, at: string, payload: object}
     */
    private static function present(TaskEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type->value,
            'actor' => ['id' => $event->actorId, 'displayName' => $event->actorName],
            'at' => $event->at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            // An object, so that an empty payload is {} on the wire and not [].
            'payload' => (object) $event->payload,
        ];
    }
}
