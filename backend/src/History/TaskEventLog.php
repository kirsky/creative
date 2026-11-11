<?php

declare(strict_types=1);

namespace App\History;

use App\Identity\User;
use App\Shared\Clock;
use App\Shared\Db\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;

/**
 * The append-only activity history of tasks (the task_events table). Every time metric is derived
 * from it, so events are never changed or removed: this class can record and read, nothing else.
 *
 * Record an event in the same Database::transaction() as the change it describes, so that the
 * history and the state cannot disagree.
 */
final class TaskEventLog
{
    private const DATETIME = 'Y-m-d H:i:s.u';

    /** Tasks per query of forTasks(). */
    private const CHUNK = 1000;

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
    ) {}

    /**
     * Appends an event stamped with the Clock's current time (stored in UTC) and returns its id.
     *
     * @param array<array-key, mixed> $payload structured data of the event (see TaskEventType): a JSON
     *     object, so a list is refused; [] is stored as {}
     * @throws InvalidArgumentException when the payload is a list or cannot be encoded as JSON
     *     (invalid UTF-8, NAN or INF, a resource)
     */
    public function record(int $taskId, TaskEventType $type, User $actor, array $payload = []): int
    {
        if ($payload !== [] && array_is_list($payload)) {
            throw new InvalidArgumentException('A task event payload must be a JSON object, not a list.');
        }

        try {
            // (object): an empty array must be stored as {}, not [].
            $json = json_encode((object) $payload, self::JSON_FLAGS);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('A task event payload must be encodable as JSON: ' . $e->getMessage(), 0, $e);
        }

        $pdo = $this->database->pdo();
        $pdo->prepare('INSERT INTO task_events (task_id, type, actor_id, at, payload) VALUES (?, ?, ?, ?, ?)')
            ->execute([
                $taskId,
                $type->value,
                $actor->id,
                $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME),
                $json,
            ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * The history of a task, oldest first. Events at the same instant keep the order they were recorded in.
     *
     * @return list<TaskEvent>
     */
    public function forTask(int $taskId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT e.id, e.task_id, e.type, e.actor_id, u.display_name AS actor_name, e.at, e.payload
             FROM task_events e
             JOIN users u ON u.id = e.actor_id
             WHERE e.task_id = ?
             ORDER BY e.at ASC, e.id ASC',
        );
        $statement->execute([$taskId]);

        return array_values(array_map(self::hydrate(...), $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * The histories of several tasks, each oldest first, in as few queries as possible (one per
     * 1000 tasks). Tasks without events are not in the result.
     *
     * @param list<int> $taskIds
     * @return array<int, list<TaskEvent>> by task id
     */
    public function forTasks(array $taskIds): array
    {
        $byTask = [];
        foreach (array_chunk($taskIds, self::CHUNK) as $chunk) {
            $statement = $this->database->pdo()->prepare(
                'SELECT e.id, e.task_id, e.type, e.actor_id, u.display_name AS actor_name, e.at, e.payload
                 FROM task_events e
                 JOIN users u ON u.id = e.actor_id
                 WHERE e.task_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')
                 ORDER BY e.at ASC, e.id ASC',
            );
            $statement->execute($chunk);

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $event = self::hydrate($row);
                $byTask[$event->taskId][] = $event;
            }
        }

        return $byTask;
    }

    private static function hydrate(mixed $row): TaskEvent
    {
        if (
            !is_array($row)
            || !is_numeric($row['id'] ?? null)
            || !is_numeric($row['task_id'] ?? null)
            || !is_string($row['type'] ?? null)
            || !is_numeric($row['actor_id'] ?? null)
            || !is_string($row['actor_name'] ?? null)
            || !is_string($row['at'] ?? null)
            || !is_string($row['payload'] ?? null)
        ) {
            throw new RuntimeException('Unexpected shape of a task_events row.');
        }

        $type = TaskEventType::tryFrom($row['type'])
            ?? throw new RuntimeException(sprintf('Unknown task event type "%s" in task_events.', $row['type']));
        $at = DateTimeImmutable::createFromFormat(self::DATETIME, $row['at'], new DateTimeZone('UTC'))
            ?: throw new RuntimeException(sprintf('Unexpected timestamp "%s" in task_events.', $row['at']));

        return new TaskEvent(
            (int) $row['id'],
            (int) $row['task_id'],
            $type,
            (int) $row['actor_id'],
            $row['actor_name'],
            $at,
            self::decodeObject($row['payload']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException('A task_events payload is not a JSON object.');
        }

        $payload = [];
        foreach ($decoded as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }
}
