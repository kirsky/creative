<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Shared\Http\ApiError;

/**
 * The state machine of spec section 4.2, and the only place that knows it. Who may cause a
 * transition is TaskPolicy's question; this class only says whether the move itself is legal.
 */
final class TaskWorkflow
{
    /**
     * Every legal move: the transition, the statuses it may start from, and the status it ends in.
     * Any (status, transition) pair not covered here is rejected.
     *
     * @var list<array{Transition, list<TaskStatus>, TaskStatus}>
     */
    private const array TRANSITIONS = [
        [Transition::Pickup, [TaskStatus::New], TaskStatus::InProgress],
        [Transition::Submit, [TaskStatus::InProgress, TaskStatus::RevisionsRequired], TaskStatus::UnderReview],
        [Transition::RequestRevisions, [TaskStatus::UnderReview], TaskStatus::RevisionsRequired],
        [Transition::Approve, [TaskStatus::UnderReview], TaskStatus::Completed],
        [Transition::Release, [TaskStatus::InProgress, TaskStatus::RevisionsRequired], TaskStatus::New],
        [
            Transition::Cancel,
            [TaskStatus::New, TaskStatus::InProgress, TaskStatus::UnderReview, TaskStatus::RevisionsRequired],
            TaskStatus::Cancelled,
        ],
    ];

    /**
     * The status a task is in after $transition, or null when $transition is not allowed from $from.
     */
    private function target(TaskStatus $from, Transition $transition): ?TaskStatus
    {
        foreach (self::TRANSITIONS as [$allowed, $sources, $target]) {
            if ($allowed === $transition && in_array($from, $sources, true)) {
                return $target;
            }
        }

        return null;
    }

    /**
     * @throws ApiError 409 invalid_transition when the move is not allowed from $from
     */
    public function next(TaskStatus $from, Transition $transition): TaskStatus
    {
        return $this->target($from, $transition) ?? throw new ApiError(409, 'invalid_transition');
    }

    public function allows(TaskStatus $from, Transition $transition): bool
    {
        return $this->target($from, $transition) !== null;
    }
}
