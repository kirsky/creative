<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * The elapsed wall-clock time of a task, in whole seconds (never "working time"; see TimeMetricsCalculator).
 *
 * The totals include intervals that are still open or that the cancellation cut short. The three lists
 * hold only closed, un-truncated intervals, in the order they happened; they feed the averages.
 */
final readonly class TaskTimeMetrics
{
    /**
     * @param int $queueWaitSeconds total time in New
     * @param int|null $cycleSeconds first pickup to the end of the task; null when it was never picked up
     * @param int $reviewSeconds total of submission to decision, over the rounds
     * @param int $revisionTurnaroundSeconds total of revisions requested to resubmission
     * @param list<int> $queueIntervals time in New that ended with a pickup
     * @param list<int> $reviewDurations reviews that ended with a decision
     * @param list<int> $revisionTurnarounds revision requests that ended with a resubmission
     */
    public function __construct(
        public int $queueWaitSeconds,
        public ?int $cycleSeconds,
        public int $reviewSeconds,
        public int $revisionTurnaroundSeconds,
        public array $queueIntervals = [],
        public array $reviewDurations = [],
        public array $revisionTurnarounds = [],
    ) {}
}
