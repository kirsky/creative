<?php

declare(strict_types=1);

namespace App\Rounds;

use App\Shared\Db\Database;
use App\Shared\Db\Row;
use App\Tasks\Person;
use App\Tasks\RoundReader;
use App\Tasks\RoundSummary;
use App\Tasks\RoundView;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * The read side of rounds, for the board (summaries, one query for all cards) and the task page
 * (the rounds with their files and AI usage).
 */
final class RoundQuery implements RoundReader
{
    private const string DATETIME = 'Y-m-d H:i:s.u';

    public function __construct(private readonly Database $database) {}

    /**
     * The credits are the sum of the AI usage lines over all rounds of a task, computed by the
     * database as a DECIMAL and returned as a string with two decimals. A task is returning when it
     * is New again and the round with the highest number was sent back for revisions.
     */
    public function summaries(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT r.task_id, t.status, COUNT(*) AS round_count,
                    (SELECT latest.decision FROM rounds latest WHERE latest.task_id = r.task_id ORDER BY latest.number DESC LIMIT 1) AS latest_decision
             FROM rounds r JOIN tasks t ON t.id = r.task_id
             WHERE r.task_id IN (' . implode(',', array_fill(0, count($taskIds), '?')) . ')
             GROUP BY r.task_id, t.status',
        );
        $statement->execute($taskIds);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $statement = $this->database->pdo()->prepare(
            'SELECT r.task_id, SUM(u.credits) AS credits
             FROM ai_usage u JOIN rounds r ON r.id = u.round_id
             WHERE r.task_id IN (' . implode(',', array_fill(0, count($taskIds), '?')) . ')
             GROUP BY r.task_id',
        );
        $statement->execute($taskIds);
        $credits = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $credits[Row::int($row, 'task_id')] = Row::string($row, 'credits');
        }

        $summaries = [];
        foreach ($rows as $row) {
            $summaries[Row::int($row, 'task_id')] = new RoundSummary(
                Row::int($row, 'round_count'),
                $credits[Row::int($row, 'task_id')] ?? '0.00',
                Row::string($row, 'status') === 'new' && Row::nullableString($row, 'latest_decision') === 'revisions_requested',
            );
        }

        return $summaries;
    }

    public function forTask(int $taskId): array
    {
        $pdo = $this->database->pdo();

        $statement = $pdo->prepare(
            'SELECT r.id, r.number, r.submitted_at, r.submitted_by, u.display_name, r.note, r.decision, r.decision_reason, r.decided_at
             FROM rounds r JOIN users u ON u.id = r.submitted_by
             WHERE r.task_id = ?
             ORDER BY r.number ASC',
        );
        $statement->execute([$taskId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $files = [];
        if ($rows !== []) {
            $statement = $pdo->prepare(
                'SELECT d.round_id, f.id, f.name, f.size, f.mime
                 FROM deliverables d
                 JOIN rounds r ON r.id = d.round_id
                 JOIN files f ON f.id = d.file_id
                 WHERE r.task_id = ?
                 ORDER BY d.id ASC',
            );
            $statement->execute([$taskId]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $file) {
                $files[Row::int($file, 'round_id')][] = [
                    'id' => Row::int($file, 'id'),
                    'name' => Row::string($file, 'name'),
                    'size' => Row::int($file, 'size'),
                    'mime' => Row::string($file, 'mime'),
                ];
            }
        }

        $usage = [];
        if ($rows !== []) {
            $statement = $pdo->prepare(
                'SELECT u.round_id, p.name, u.credits
                 FROM ai_usage u
                 JOIN rounds r ON r.id = u.round_id
                 JOIN ai_providers p ON p.id = u.provider_id
                 WHERE r.task_id = ?
                 ORDER BY u.id ASC',
            );
            $statement->execute([$taskId]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $line) {
                $usage[Row::int($line, 'round_id')][] = ['provider' => Row::string($line, 'name'), 'credits' => Row::string($line, 'credits')];
            }
        }

        $rounds = [];
        foreach ($rows as $row) {
            $decidedAt = Row::nullableString($row, 'decided_at');
            $rounds[] = new RoundView(
                Row::int($row, 'number'),
                self::time(Row::string($row, 'submitted_at')),
                new Person(Row::int($row, 'submitted_by'), Row::string($row, 'display_name')),
                Row::nullableString($row, 'note'),
                $files[Row::int($row, 'id')] ?? [],
                Row::nullableString($row, 'decision'),
                Row::nullableString($row, 'decision_reason'),
                $decidedAt === null ? null : self::time($decidedAt),
                $usage[Row::int($row, 'id')] ?? [],
            );
        }

        return $rounds;
    }

    private static function time(string $stored): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat(self::DATETIME, $stored, new DateTimeZone('UTC'))
            ?: throw new RuntimeException(sprintf('Unexpected timestamp "%s" in rounds.', $stored));
    }
}
