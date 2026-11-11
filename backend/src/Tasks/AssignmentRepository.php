<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Shared\Db\Database;
use App\Shared\Db\Row;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * The assignments table: who has worked on a task. Rows are only ever added and closed, never
 * deleted. The database allows one open row per task (see the CreateAssignments migration).
 *
 * Nothing here opens a transaction: AssignmentService wraps each change in one.
 */
final class AssignmentRepository
{
    private const string DATETIME = 'Y-m-d H:i:s.u';

    public function __construct(private readonly Database $database) {}

    /**
     * Opens an assignment of $taskId to $designerId and returns its id.
     */
    public function open(int $taskId, int $designerId, DateTimeImmutable $at): int
    {
        $pdo = $this->database->pdo();
        $pdo->prepare('INSERT INTO assignments (task_id, designer_id, assigned_at) VALUES (?, ?, ?)')
            ->execute([$taskId, $designerId, self::stamp($at)]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Closes the open assignment of $taskId with $how (released, completed or cancelled) and returns
     * its id.
     *
     * @throws RuntimeException when the task has no open assignment, which would mean the task and its
     *     assignments disagree
     */
    public function closeOpen(int $taskId, string $how, DateTimeImmutable $at): int
    {
        return $this->closeOpenIfAny($taskId, $how, $at)
            ?? throw new RuntimeException(sprintf('Task %d has no open assignment to close.', $taskId));
    }

    /**
     * Closes the open assignment of $taskId with $how, if it has one, and returns its id; null when
     * it has none (a task nobody ever picked up).
     */
    public function closeOpenIfAny(int $taskId, string $how, DateTimeImmutable $at): ?int
    {
        $pdo = $this->database->pdo();

        $statement = $pdo->prepare('SELECT id FROM assignments WHERE task_id = ? AND ended_at IS NULL FOR UPDATE');
        $statement->execute([$taskId]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            return null;
        }

        $pdo->prepare('UPDATE assignments SET ended_at = ?, ended_how = ? WHERE id = ?')
            ->execute([self::stamp($at), $how, $id]);

        return (int) $id;
    }

    /**
     * The assignments of a task, oldest first.
     *
     * @return list<Assignment>
     */
    public function forTask(int $taskId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT a.id, a.designer_id, u.display_name, a.assigned_at, a.ended_at, a.ended_how
             FROM assignments a
             JOIN users u ON u.id = a.designer_id
             WHERE a.task_id = ?
             ORDER BY a.assigned_at ASC, a.id ASC',
        );
        $statement->execute([$taskId]);

        $assignments = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $endedAt = Row::nullableString($row, 'ended_at');
            $assignments[] = new Assignment(
                Row::int($row, 'id'),
                new Person(Row::int($row, 'designer_id'), Row::string($row, 'display_name')),
                self::time(Row::string($row, 'assigned_at')),
                $endedAt === null ? null : self::time($endedAt),
                Row::nullableString($row, 'ended_how'),
            );
        }

        return $assignments;
    }

    private static function stamp(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME);
    }

    private static function time(string $stored): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat(self::DATETIME, $stored, new DateTimeZone('UTC'))
            ?: throw new RuntimeException(sprintf('Unexpected timestamp "%s" in assignments.', $stored));
    }
}
