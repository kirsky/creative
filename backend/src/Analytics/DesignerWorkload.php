<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Shared\Db\Database;
use App\Shared\Db\Row;
use App\Tasks\TaskQuery;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * What each designer holds at this moment: one row for every user with the designer role, zeros
 * included, counting the tasks assigned to them that are in progress, under review or in revisions.
 * Completed and cancelled tasks are not workload. Ordered by display name, then id.
 */
final class DesignerWorkload
{
    public function __construct(private readonly Database $database) {}

    /**
     * @return list<array{designerId: int, name: string, inProgress: int, revisionsRequired: int, underReview: int, overdue: int}>
     */
    public function at(DateTimeImmutable $now): array
    {
        // The join keeps only the three statuses of workload, so OVERDUE (which also excludes the
        // terminal ones) counts their overdue tasks.
        $statement = $this->database->pdo()->prepare(
            "SELECT u.id, u.display_name,
                    COALESCE(SUM(t.status = 'in_progress'), 0) AS in_progress,
                    COALESCE(SUM(t.status = 'revisions_required'), 0) AS revisions_required,
                    COALESCE(SUM(t.status = 'under_review'), 0) AS under_review,
                    COALESCE(SUM(" . TaskQuery::OVERDUE . "), 0) AS overdue
             FROM users u
             LEFT JOIN tasks t ON t.assignee_id = u.id AND t.status IN ('in_progress', 'revisions_required', 'under_review')
             WHERE u.role = 'designer'
             GROUP BY u.id, u.display_name
             ORDER BY u.display_name ASC, u.id ASC",
        );
        $statement->execute([$now->setTimezone(new DateTimeZone('UTC'))->format(TaskQuery::DATETIME)]);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'designerId' => Row::int($row, 'id'),
                'name' => Row::string($row, 'display_name'),
                'inProgress' => Row::int($row, 'in_progress'),
                'revisionsRequired' => Row::int($row, 'revisions_required'),
                'underReview' => Row::int($row, 'under_review'),
                'overdue' => Row::int($row, 'overdue'),
            ];
        }

        return $rows;
    }
}
