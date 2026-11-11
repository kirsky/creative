<?php

declare(strict_types=1);

namespace App\Rounds;

use App\Shared\Db\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Writes rounds and their deliverables. The read side, for the board and the task page, is RoundQuery.
 *
 * Nothing here opens a transaction: RoundService wraps each change in one, after locking the task.
 */
final class RoundRepository
{
    private const string DATETIME = 'Y-m-d H:i:s.u';

    public function __construct(private readonly Database $database) {}

    /**
     * The number the next round of $taskId gets: one more than the highest so far. Only reliable
     * while the task row is locked; UNIQUE (task_id, number) refuses a clash anyway.
     */
    public function nextNumber(int $taskId): int
    {
        $statement = $this->database->pdo()->prepare('SELECT COALESCE(MAX(number), 0) + 1 FROM rounds WHERE task_id = ?');
        $statement->execute([$taskId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Stores an undecided round and returns its id.
     */
    public function insert(int $taskId, int $number, int $designerId, DateTimeImmutable $at, ?string $note): int
    {
        $pdo = $this->database->pdo();
        $pdo->prepare('INSERT INTO rounds (task_id, number, submitted_by, submitted_at, note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$taskId, $number, $designerId, $at->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME), $note]);

        return (int) $pdo->lastInsertId();
    }

    public function addDeliverable(int $roundId, int $fileId): void
    {
        $this->database->pdo()->prepare('INSERT INTO deliverables (round_id, file_id) VALUES (?, ?)')->execute([$roundId, $fileId]);
    }
}
