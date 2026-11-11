<?php

declare(strict_types=1);

namespace App\Rounds;

use App\Shared\Db\Database;
use App\Shared\Db\Row;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

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

    /**
     * The round of $taskId with the highest number, locked until the transaction ends, or null when
     * the task has none. Call it inside Database::transaction(), after locking the task.
     *
     * @return array{id: int, number: int, decided: bool}|null
     */
    public function latestForUpdate(int $taskId): ?array
    {
        $statement = $this->database->pdo()->prepare('SELECT id, number, decision FROM rounds WHERE task_id = ? ORDER BY number DESC LIMIT 1 FOR UPDATE');
        $statement->execute([$taskId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return ['id' => Row::int($row, 'id'), 'number' => Row::int($row, 'number'), 'decided' => Row::nullableString($row, 'decision') !== null];
    }

    /**
     * Records the buyer's decision on a round, with its time, in one guarded UPDATE that changes the
     * row only while it is still undecided. Returns false when it did not.
     */
    public function decide(int $roundId, string $decision, ?string $reason, DateTimeImmutable $at): bool
    {
        $statement = $this->database->pdo()->prepare(
            'UPDATE rounds SET decision = ?, decision_reason = ?, decided_at = ? WHERE id = ? AND decision IS NULL',
        );
        $statement->execute([$decision, $reason, $at->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME), $roundId]);

        return $statement->rowCount() === 1;
    }

    public function addDeliverable(int $roundId, int $fileId): void
    {
        $this->database->pdo()->prepare('INSERT INTO deliverables (round_id, file_id) VALUES (?, ?)')->execute([$roundId, $fileId]);
    }
}
