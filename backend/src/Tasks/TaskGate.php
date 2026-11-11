<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Identity\User;
use App\Shared\Http\ApiError;

/**
 * The start of every change to a task: lock the row, then decide whether the actor may touch it.
 *
 * Call it first inside Database::transaction(). The lock is held to the end of that transaction, so
 * the status and the assignee that are read here are still true when the caller writes. The answers
 * are always in this order, and the caller adds the state rule after them (TaskWorkflow::next(), a
 * 409 invalid_transition, or its own such as task_not_editable):
 *
 *   404 not_found: no such task, or the actor may not view it;
 *   403 forbidden: the actor can view it but is not who does this (TaskPolicy::entitled()).
 */
final class TaskGate
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly TaskPolicy $policy,
    ) {}

    /**
     * The task, locked, when $actor is entitled to do $action to it.
     *
     * @throws ApiError 404 not_found, 403 forbidden
     */
    public function lockAndAuthorize(User $actor, int $taskId, Action $action): Task
    {
        $task = $this->lock($taskId);

        if (!$this->policy->can($actor, Action::ViewTask, $task)) {
            throw ApiError::notFound();
        }

        if (!$this->policy->entitled($actor, $action, $task)) {
            throw ApiError::forbidden();
        }

        return $task;
    }

    /**
     * The task, locked, for a caller that has its own order of answers (AssignmentService::pickUp()).
     *
     * @throws ApiError 404 not_found when there is no such task
     */
    public function lock(int $taskId): Task
    {
        return $this->tasks->findForUpdate($taskId) ?? throw ApiError::notFound();
    }
}
