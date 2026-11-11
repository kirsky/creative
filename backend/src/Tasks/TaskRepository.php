<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Identity\User;
use App\Shared\Db\Database;
use App\Shared\Db\Row;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * Writes a task and the rows that hang on it, and finds the part of a task that decides who may do
 * what. The read side of the board and the task page is TaskQuery.
 *
 * Nothing here opens a transaction: TaskService wraps the whole creation in one.
 */
final class TaskRepository
{
    public const string DATETIME = 'Y-m-d H:i:s';

    public function __construct(private readonly Database $database) {}

    /**
     * Stores a New task with its geo. References are added one by one with addUrl()/addFile().
     */
    public function insert(User $creator, NewTask $new, DateTimeImmutable $now): Task
    {
        $pdo = $this->database->pdo();
        $stamp = $now->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME);

        $pdo->prepare(
            'INSERT INTO tasks (offer, creative_type, quantity, description, priority, deadline_at, created_by, status, assignee_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)',
        )->execute([
            $new->offer,
            $new->creativeType->value,
            $new->quantity,
            $new->description,
            $new->priority->value,
            $new->deadline->format(self::DATETIME),
            $creator->id,
            TaskStatus::New->value,
            $stamp,
            $stamp,
        ]);
        $id = (int) $pdo->lastInsertId();

        $geo = $pdo->prepare('INSERT INTO task_geo (task_id, code) VALUES (?, ?)');
        foreach ($new->geo as $code) {
            $geo->execute([$id, $code]);
        }

        return new Task($id, $creator->id, null, TaskStatus::New, $new->deadline);
    }

    public function addUrl(int $taskId, string $url): void
    {
        $this->database->pdo()->prepare("INSERT INTO task_references (task_id, kind, url, file_id) VALUES (?, 'url', ?, NULL)")
            ->execute([$taskId, $url]);
    }

    public function addFile(int $taskId, int $fileId): void
    {
        $this->database->pdo()->prepare("INSERT INTO task_references (task_id, kind, url, file_id) VALUES (?, 'file', NULL, ?)")
            ->execute([$taskId, $fileId]);
    }

    public function find(int $id): ?Task
    {
        $statement = $this->database->pdo()->prepare('SELECT id, created_by, assignee_id, status, deadline_at FROM tasks WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Builds a Task from a row holding id, created_by, assignee_id, status and deadline_at.
     */
    public static function hydrate(mixed $row): Task
    {
        return new Task(
            Row::int($row, 'id'),
            Row::int($row, 'created_by'),
            Row::nullableInt($row, 'assignee_id'),
            TaskStatus::tryFrom(Row::string($row, 'status')) ?? throw new RuntimeException('Unknown task status in tasks.'),
            self::time(Row::string($row, 'deadline_at')),
        );
    }

    /**
     * A stored UTC timestamp as a DateTimeImmutable.
     */
    public static function time(string $stored): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat(self::DATETIME, $stored, new DateTimeZone('UTC'))
            ?: throw new RuntimeException(sprintf('Unexpected timestamp "%s" in the database.', $stored));
    }
}
