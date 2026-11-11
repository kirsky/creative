<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Tasks\TaskCard;
use JsonSerializable;

/**
 * The manager dashboard as GET /api/analytics/dashboard sends it (schema Dashboard).
 */
final readonly class Dashboard implements JsonSerializable
{
    /**
     * @param array{total: int, active: int, cancelled: int, byStatus: array<string, int>} $totals
     * @param list<TaskCard> $overdueTasks the first of the overdue tasks, not all of them
     * @param array{cycleSeconds: int|null, queueWaitSeconds: int|null, reviewSecondsPerRound: int|null, revisionTurnaroundSeconds: int|null, completedCount: int} $averages
     * @param list<array{designerId: int, name: string, inProgress: int, revisionsRequired: int, underReview: int, overdue: int}> $workload
     * @param array{total: string, byProvider: list<array{provider: string, credits: string}>, byDesigner: list<array{designerId: int, name: string, total: string, byProvider: list<array{provider: string, credits: string}>}>} $ai
     */
    public function __construct(
        public array $totals,
        public int $overdueCount,
        public array $overdueTasks,
        public array $averages,
        public array $workload,
        public array $ai,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'totals' => $this->totals,
            'overdue' => ['count' => $this->overdueCount, 'tasks' => $this->overdueTasks],
            'averages' => $this->averages,
            'workload' => $this->workload,
            'ai' => $this->ai,
        ];
    }
}
