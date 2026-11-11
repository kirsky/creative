<?php

declare(strict_types=1);

namespace App\Tasks;

use App\History\TaskEventLog;
use App\Identity\Role;
use App\Metrics\TimeMetricsCalculator;
use App\Identity\User;
use App\Shared\Clock;
use App\Shared\Db\Database;
use App\Shared\Db\Row;
use App\Shared\Http\ApiError;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * The read side of tasks: the board and the task page, as the viewer is allowed to see them.
 *
 * A buyer sees the tasks they created, a designer the New pool and the tasks assigned to them, a
 * manager all (the SQL below repeats TaskPolicy's ViewTask rule; BoardQueryTest checks they agree).
 * A task the viewer may not see does not exist for them: detail() answers 404 not_found.
 */
final class TaskQuery
{
    /** The escape character of LIKE patterns built from user input. */
    private const string LIKE_ESCAPE = '!';

    private const string SELECT = 'SELECT t.id, t.offer, t.creative_type, t.quantity, t.description, t.priority, t.deadline_at, t.status,
            t.created_by, t.assignee_id, creator.display_name AS creator_name, assignee.display_name AS assignee_name
        FROM tasks t
        JOIN users creator ON creator.id = t.created_by
        LEFT JOIN users assignee ON assignee.id = t.assignee_id';

    /**
     * The rule of TaskCard::isOverdue as SQL on the alias t: the deadline is strictly before now (one
     * parameter, a DATETIME string) and the task is still open.
     */
    public const string OVERDUE = "t.deadline_at < ? AND t.status NOT IN ('completed', 'cancelled')";

    public const string DATETIME = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly TaskPolicy $policy,
        private readonly RoundReader $rounds,
        private readonly AssignmentRepository $assignments,
        private readonly TaskEventLog $events,
        private readonly TimeMetricsCalculator $metrics,
    ) {}

    /**
     * The tasks $actor may see that match $filter, by deadline (earliest first), then id.
     *
     * @return list<TaskCard>
     */
    public function board(User $actor, BoardFilter $filter): array
    {
        $where = [];
        $parameters = [];

        // The viewer's scope: TaskPolicy's ViewTask rule, as SQL.
        // Explicit for every role, and a role this code does not know fails closed (UnhandledMatchError).
        match ($actor->role) {
            Role::Buyer => [$where[], $parameters[]] = ['t.created_by = ?', $actor->id],
            Role::Designer => [$where[], $parameters[]] = ["(t.status = 'new' OR t.assignee_id = ?)", $actor->id],
            Role::Manager => null, // Sees everything.
        };

        if (!$filter->includeCancelled) {
            $where[] = "t.status <> 'cancelled'";
        }
        if ($filter->priority !== null) {
            [$where[], $parameters[]] = ['t.priority = ?', $filter->priority];
        }
        if ($filter->creativeType !== null) {
            [$where[], $parameters[]] = ['t.creative_type = ?', $filter->creativeType];
        }
        if ($filter->designerId !== null) {
            [$where[], $parameters[]] = ['t.assignee_id = ?', $filter->designerId];
        }
        $q = trim($filter->q ?? '');
        if ($q !== '') {
            $escaped = str_replace([self::LIKE_ESCAPE, '%', '_'], [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'], $q);
            [$where[], $parameters[]] = ["t.offer LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'", '%' . $escaped . '%'];
        }

        $statement = $this->database->pdo()->prepare(
            self::SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY t.deadline_at ASC, t.id ASC',
        );
        $statement->execute($parameters);

        return $this->cards($actor, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The overdue tasks (OVERDUE), by deadline (earliest first), then id; at most $limit. For the
     * manager dashboard: a user who may not view it gets 403, since the SQL is not scoped to a viewer.
     *
     * @return list<TaskCard>
     * @throws ApiError 403 forbidden unless $actor may ViewDashboard
     */
    public function overdue(User $actor, int $limit): array
    {
        if (!$this->policy->can($actor, Action::ViewDashboard)) {
            throw ApiError::forbidden();
        }

        $statement = $this->database->pdo()->prepare(
            self::SELECT . ' WHERE ' . self::OVERDUE . ' ORDER BY t.deadline_at ASC, t.id ASC LIMIT ' . max(0, $limit),
        );
        $statement->execute([$this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME)]);

        return $this->cards($actor, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @throws ApiError 404 not_found when there is no such task or $actor may not view it
     */
    public function detail(User $actor, int $id): TaskDetail
    {
        $this->viewable($actor, $id);

        $statement = $this->database->pdo()->prepare(self::SELECT . ' WHERE t.id = ?');
        $statement->execute([$id]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $card = $this->cards($actor, $rows)[0] ?? throw ApiError::notFound();

        return new TaskDetail(
            $card,
            Row::string($rows[0], 'description'),
            $this->references($id),
            $this->assignments->forTask($id),
            $this->rounds->forTask($id),
            $this->metrics->calculate($this->events->forTask($id), $this->clock->now()),
        );
    }

    /**
     * The task if $actor may view it.
     *
     * @throws ApiError 404 not_found when there is no such task or $actor may not view it
     */
    public function viewable(User $actor, int $id): Task
    {
        $statement = $this->database->pdo()->prepare('SELECT id, created_by, assignee_id, status, deadline_at FROM tasks WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        $task = $row === false ? null : TaskRepository::hydrate($row);
        if ($task === null || !$this->policy->can($actor, Action::ViewTask, $task)) {
            throw ApiError::notFound();
        }

        return $task;
    }

    /**
     * @param array<array-key, mixed> $rows rows of SELECT
     * @return list<TaskCard>
     */
    private function cards(User $actor, array $rows): array
    {
        $ids = array_values(array_map(static fn(mixed $row): int => Row::int($row, 'id'), $rows));
        $geo = $this->geo($ids);
        $summaries = $ids === [] ? [] : $this->rounds->summaries($ids);
        $now = $this->clock->now();

        $cards = [];
        foreach ($rows as $row) {
            $task = TaskRepository::hydrate($row);
            $summary = $summaries[$task->id] ?? RoundSummary::none();
            $assigneeId = $task->assigneeId;

            $cards[] = new TaskCard(
                $task->id,
                Row::string($row, 'offer'),
                $geo[$task->id] ?? [],
                CreativeType::tryFrom(Row::string($row, 'creative_type')) ?? throw new RuntimeException('Unknown creative type in tasks.'),
                Row::int($row, 'quantity'),
                Priority::tryFrom(Row::string($row, 'priority')) ?? throw new RuntimeException('Unknown priority in tasks.'),
                $task->deadlineAt,
                $task->status,
                new Person($task->createdBy, Row::string($row, 'creator_name')),
                $assigneeId === null ? null : new Person($assigneeId, Row::string($row, 'assignee_name')),
                $summary->roundCount,
                $summary->creditsTotal,
                // Strictly after the deadline, and still open: a deadline of exactly now is not overdue.
                $task->deadlineAt < $now && !$task->status->isTerminal(),
                $task->status === TaskStatus::New && $summary->isReturning,
                $this->policy->allowedActions($actor, $task),
            );
        }

        return $cards;
    }

    /**
     * @param list<int> $taskIds
     * @return array<int, list<string>> the codes of each task, alphabetical
     */
    private function geo(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT task_id, code FROM task_geo WHERE task_id IN (' . implode(',', array_fill(0, count($taskIds), '?')) . ') ORDER BY task_id, code',
        );
        $statement->execute($taskIds);

        $geo = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $geo[Row::int($row, 'task_id')][] = Row::string($row, 'code');
        }

        return $geo;
    }

    /**
     * @return list<array<string, int|string>>
     */
    private function references(int $taskId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT r.kind, r.url, r.file_id, f.name, f.mime, f.size
             FROM task_references r LEFT JOIN files f ON f.id = r.file_id
             WHERE r.task_id = ? ORDER BY r.id',
        );
        $statement->execute([$taskId]);

        $references = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $references[] = Row::string($row, 'kind') === 'url'
                ? ['type' => 'url', 'url' => Row::string($row, 'url')]
                : [
                    'type' => 'file',
                    'fileId' => Row::int($row, 'file_id'),
                    'name' => Row::string($row, 'name'),
                    'mime' => Row::string($row, 'mime'),
                    'size' => Row::int($row, 'size'),
                ];
        }

        return $references;
    }
}
