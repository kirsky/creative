<?php

declare(strict_types=1);

namespace App\History;

use DateTimeImmutable;

/**
 * One entry of a task's history, as read back by TaskEventLog.
 *
 * $at is UTC. $actorName is the actor's display name at the time of reading, not when the event
 * happened. $payload is the structured data of the event (see TaskEventType); [] when it had none.
 */
final readonly class TaskEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int $id,
        public int $taskId,
        public TaskEventType $type,
        public int $actorId,
        public string $actorName,
        public DateTimeImmutable $at,
        public array $payload,
    ) {}
}
