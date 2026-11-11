<?php

declare(strict_types=1);

namespace App\Analytics;

use App\AiUsage\AiUsageAggregator;
use App\AiUsage\Period;
use App\History\TaskEventLog;
use App\Identity\User;
use App\Shared\Clock;
use App\Shared\Db\Database;
use App\Shared\Db\Row;
use App\Shared\Http\ApiError;
use App\Tasks\Action;
use App\Tasks\TaskPolicy;
use App\Tasks\TaskQuery;
use App\Tasks\TaskStatus;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * The read side of the manager dashboard (spec section 7).
 *
 * The period narrows the totals and averages to tasks created in it, and the AI credits to rounds
 * submitted in it. Workload and the overdue list are the situation now, whatever the period.
 * Demo scale: the tasks of the period are read into memory, with all their events in one grouped query.
 */
final class DashboardQuery
{
    /** How many overdue tasks the dashboard lists (the count is always complete). */
    public const int OVERDUE_LIST_MAX = 50;

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly TaskPolicy $policy,
        private readonly TaskQuery $tasks,
        private readonly TaskEventLog $events,
        private readonly TimeAverages $averages,
        private readonly DesignerWorkload $workload,
        private readonly AiUsageAggregator $ai,
    ) {}

    /**
     * @throws ApiError 403 forbidden unless $actor is a manager
     */
    public function dashboard(User $actor, Period $period): Dashboard
    {
        if (!$this->policy->can($actor, Action::ViewDashboard)) {
            throw ApiError::forbidden();
        }

        $now = $this->clock->now();
        $statuses = $this->statusesCreatedIn($period);

        return new Dashboard(
            self::totals($statuses),
            $this->overdueCount($now),
            $this->tasks->overdue($actor, self::OVERDUE_LIST_MAX),
            $this->averages->calculate($statuses, $this->events->forTasks(array_keys($statuses)), $now),
            $this->workload->at($now),
            [
                'total' => $this->ai->total($period),
                'byProvider' => $this->ai->byProvider($period),
                'byDesigner' => $this->ai->byDesigner($period),
            ],
        );
    }

    /**
     * The status of every task created in $period, by id.
     *
     * @return array<int, TaskStatus>
     */
    private function statusesCreatedIn(Period $period): array
    {
        $sql = 'SELECT id, status FROM tasks';
        $parameters = [];
        if ($period->from !== null) {
            $sql .= ' WHERE created_at >= ?';
            // created_at has whole seconds: a task created in the second the period starts is in.
            $parameters[] = $period->from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($parameters);

        $statuses = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $statuses[Row::int($row, 'id')] = TaskStatus::tryFrom(Row::string($row, 'status'))
                ?? throw new RuntimeException('Unknown status in tasks.');
        }

        return $statuses;
    }

    /**
     * @param array<int, TaskStatus> $statuses
     * @return array{total: int, active: int, cancelled: int, byStatus: array<string, int>}
     */
    private static function totals(array $statuses): array
    {
        $byStatus = [];
        foreach (TaskStatus::cases() as $status) {
            $byStatus[$status->value] = 0;
        }
        foreach ($statuses as $status) {
            ++$byStatus[$status->value];
        }

        return [
            'total' => count($statuses),
            // In flight with a designer: not New (nobody works on it yet), not terminal.
            'active' => $byStatus[TaskStatus::InProgress->value] + $byStatus[TaskStatus::UnderReview->value] + $byStatus[TaskStatus::RevisionsRequired->value],
            'cancelled' => $byStatus[TaskStatus::Cancelled->value],
            'byStatus' => $byStatus,
        ];
    }

    private function overdueCount(DateTimeImmutable $now): int
    {
        $statement = $this->database->pdo()->prepare('SELECT COUNT(*) AS n FROM tasks t WHERE ' . TaskQuery::OVERDUE);
        $statement->execute([$now->setTimezone(new DateTimeZone('UTC'))->format(TaskQuery::DATETIME)]);

        return Row::int($statement->fetch(PDO::FETCH_ASSOC), 'n');
    }
}
