<?php

declare(strict_types=1);

namespace App\Analytics;

use App\History\TaskEvent;
use App\Metrics\TimeMetricsCalculator;
use App\Tasks\TaskStatus;
use DateTimeImmutable;

/**
 * The dashboard's averages over a set of tasks, from their events. Each is the floor of the mean,
 * whole seconds, or null when nothing qualifies (never a division by zero).
 *
 * - cycleSeconds: mean cycle of the completed tasks (an open or cancelled task has no finished cycle).
 * - queueWaitSeconds, reviewSecondsPerRound, revisionTurnaroundSeconds: mean of the closed intervals
 *   of all the tasks, each interval counting once.
 */
final class TimeAverages
{
    public function __construct(private readonly TimeMetricsCalculator $metrics) {}

    /**
     * @param array<int, TaskStatus> $statuses the tasks in scope, by id
     * @param array<int, list<TaskEvent>> $events their events, by task id (a task may have none)
     * @return array{cycleSeconds: int|null, queueWaitSeconds: int|null, reviewSecondsPerRound: int|null, revisionTurnaroundSeconds: int|null, completedCount: int}
     */
    public function calculate(array $statuses, array $events, DateTimeImmutable $now): array
    {
        $cycles = $queue = $reviews = $turnarounds = [];

        foreach ($statuses as $id => $status) {
            $metrics = $this->metrics->calculate($events[$id] ?? [], $now);

            if ($status === TaskStatus::Completed && $metrics->cycleSeconds !== null) {
                $cycles[] = $metrics->cycleSeconds;
            }
            array_push($queue, ...$metrics->queueIntervals);
            array_push($reviews, ...$metrics->reviewDurations);
            array_push($turnarounds, ...$metrics->revisionTurnarounds);
        }

        return [
            'cycleSeconds' => self::mean($cycles),
            'queueWaitSeconds' => self::mean($queue),
            'reviewSecondsPerRound' => self::mean($reviews),
            'revisionTurnaroundSeconds' => self::mean($turnarounds),
            'completedCount' => count($cycles),
        ];
    }

    /**
     * @param list<int> $values
     */
    private static function mean(array $values): ?int
    {
        return $values === [] ? null : intdiv(array_sum($values), count($values));
    }
}
