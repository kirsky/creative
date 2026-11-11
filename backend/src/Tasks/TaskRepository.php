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
 * Nothing here opens a transaction: TaskService wraps each change in one.
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

    /**
     * The task, locked against concurrent changes until the transaction it is called in ends.
     * Call it inside Database::transaction().
     */
    public function findForUpdate(int $id): ?Task
    {
        return $this->find($id, true);
    }

    /**
     * The brief as stored, in the normalised form of BriefEdit::values(), for every field of
     * BriefEdit::FIELDS.
     *
     * @return array<string, mixed>
     */
    public function brief(int $id): array
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare('SELECT offer, creative_type, quantity, description, priority, deadline_at FROM tasks WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException(sprintf('Task %d is gone.', $id));
        }

        $geo = $pdo->prepare('SELECT code FROM task_geo WHERE task_id = ? ORDER BY code');
        $geo->execute([$id]);

        return [
            'offer' => Row::string($row, 'offer'),
            'geo' => array_map(static fn(mixed $code): string => is_string($code) ? $code : '', $geo->fetchAll(PDO::FETCH_COLUMN)),
            'creativeType' => Row::string($row, 'creative_type'),
            'quantity' => Row::int($row, 'quantity'),
            'description' => Row::string($row, 'description'),
            'priority' => Row::string($row, 'priority'),
            'deadline' => BriefEdit::deadlineText(self::time(Row::string($row, 'deadline_at'))),
        ];
    }

    /**
     * Writes the fields $edit names (the geo replaced as a whole) and sets updated_at to $now.
     * Nothing is written when it names none.
     */
    public function updateBrief(int $id, BriefEdit $edit, DateTimeImmutable $now): void
    {
        $columns = [
            'offer' => $edit->offer,
            'creative_type' => $edit->creativeType?->value,
            'quantity' => $edit->quantity,
            'description' => $edit->description,
            'priority' => $edit->priority?->value,
            'deadline_at' => $edit->deadline?->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME),
        ];
        $columns = array_filter($columns, static fn(mixed $value): bool => $value !== null);
        if ($columns === [] && $edit->geo === null) {
            return;
        }

        $columns['updated_at'] = $now->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME);
        $pdo = $this->database->pdo();

        // The column names come from the list above, never from the client.
        $assignments = implode(', ', array_map(static fn(string $column): string => $column . ' = ?', array_keys($columns)));
        $pdo->prepare('UPDATE tasks SET ' . $assignments . ' WHERE id = ?')->execute([...array_values($columns), $id]);

        if ($edit->geo !== null) {
            $pdo->prepare('DELETE FROM task_geo WHERE task_id = ?')->execute([$id]);
            $insert = $pdo->prepare('INSERT INTO task_geo (task_id, code) VALUES (?, ?)');
            foreach ($edit->geo as $code) {
                $insert->execute([$id, $code]);
            }
        }
    }

    /**
     * Gives an unassigned task in status $from to $designerId, moving it to $to, in one guarded
     * UPDATE: it changes the row only if it is still in $from and still has no assignee, whatever
     * was read before. Returns false when it did not (somebody else got there first).
     */
    public function claim(int $id, int $designerId, TaskStatus $from, TaskStatus $to, DateTimeImmutable $now): bool
    {
        $statement = $this->database->pdo()->prepare(
            'UPDATE tasks SET status = ?, assignee_id = ?, updated_at = ? WHERE id = ? AND status = ? AND assignee_id IS NULL',
        );
        $statement->execute([$to->value, $designerId, self::stamp($now), $id, $from->value]);

        return $statement->rowCount() === 1;
    }

    /**
     * Takes the task from $designerId, moving it from $from to $to and leaving it with no assignee, in
     * one guarded UPDATE that changes the row only if it is still in $from and still assigned to
     * $designerId. Returns false when it did not.
     */
    public function unassign(int $id, int $designerId, TaskStatus $from, TaskStatus $to, DateTimeImmutable $now): bool
    {
        $statement = $this->database->pdo()->prepare(
            'UPDATE tasks SET status = ?, assignee_id = NULL, updated_at = ? WHERE id = ? AND status = ? AND assignee_id = ?',
        );
        $statement->execute([$to->value, self::stamp($now), $id, $from->value, $designerId]);

        return $statement->rowCount() === 1;
    }

    private static function stamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME);
    }

    public function find(int $id, bool $forUpdate = false): ?Task
    {
        $statement = $this->database->pdo()->prepare('SELECT id, created_by, assignee_id, status, deadline_at FROM tasks WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
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
