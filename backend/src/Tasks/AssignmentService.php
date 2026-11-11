<?php

declare(strict_types=1);

namespace App\Tasks;

use App\History\TaskEventLog;
use App\History\TaskEventType;
use App\Identity\Role;
use App\Identity\User;
use App\Shared\Clock;
use App\Shared\Db\Database;
use App\Shared\Http\ApiError;
use RuntimeException;

/**
 * Who is working on a task: a designer picks a New task up and may give it back.
 *
 * Every change is one transaction that starts by locking the task row, so that two designers (or a
 * designer and a cancelling buyer) cannot both act on the state they read; the status change, the
 * assignment row and the event are written together or not at all. The row is also changed by a
 * guarded UPDATE that names the state it expects, so the guarantee does not depend on the lock alone.
 */
final class AssignmentService
{
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly TaskPolicy $policy,
        private readonly TaskGate $gate,
        private readonly TaskWorkflow $workflow,
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly TaskEventLog $events,
    ) {}

    /**
     * The designer takes a New task: it becomes In Progress and assigned to them, a new assignment
     * is opened, and task_picked_up {assignmentId} is recorded.
     *
     * The answers, in the order they are decided:
     *  - 404 not_found: there is no such task, or the actor may not view it. One exception: a
     *    designer who cannot view it because another designer holds it (it has an assignee and is not
     *    completed or cancelled) lost the race for it and gets 409 already_taken, not a 404 that would
     *    say the task never existed;
     *  - 403 forbidden: the actor can view it but is not a designer (the creating buyer, a manager);
     *  - 409 invalid_transition: the task is not New (including a task the actor holds themself);
     *  - 409 already_taken: the task is New but another designer got it first (the guarded UPDATE
     *    changed no row).
     *
     * @throws ApiError see above
     */
    public function pickUp(User $designer, int $taskId): Task
    {
        return $this->database->transaction(function () use ($designer, $taskId): Task {
            // Not lockAndAuthorize(): the answers are in their own order here (see above).
            $task = $this->gate->lock($taskId);

            if (!$this->policy->can($designer, Action::ViewTask, $task)) {
                throw $designer->role === Role::Designer && self::heldByAnother($task, $designer)
                    ? new ApiError(409, 'already_taken')
                    : ApiError::notFound();
            }

            // entitled(PickUp) leaves out the status and the assignee, which are the 409s below; so only
            // somebody who is not a designer at all is refused here.
            if (!$this->policy->entitled($designer, Action::PickUp, $task)) {
                throw ApiError::forbidden();
            }

            $target = $this->workflow->next($task->status, Transition::Pickup);

            // A New task with an assignee does not exist; if one ever did, it is taken.
            if (!$this->policy->can($designer, Action::PickUp, $task)) {
                throw new ApiError(409, 'already_taken');
            }

            $now = $this->clock->now();
            if (!$this->tasks->claim($taskId, $designer->id, $task->status, $target, $now)) {
                throw new ApiError(409, 'already_taken');
            }

            $assignmentId = $this->assignments->open($taskId, $designer->id, $now);
            $this->events->record($taskId, TaskEventType::TaskPickedUp, $designer, ['assignmentId' => $assignmentId]);

            return $this->tasks->find($taskId) ?? throw new RuntimeException('The task just picked up is gone.');
        });
    }

    /**
     * The assigned designer gives the task back: it becomes New again with no assignee, its open
     * assignment is closed as released, and task_released {assignmentId} is recorded. Rounds, files
     * and feedback stay with the task.
     *
     * @throws ApiError 404 not_found when there is no such task or the actor may not view it (a
     *     designer who is not the assignee cannot view a task in progress); 403 forbidden when they
     *     can view it but are not its assigned designer; 409 invalid_transition when the task is not
     *     In Progress or Revisions Required
     */
    public function release(User $designer, int $taskId): Task
    {
        return $this->database->transaction(function () use ($designer, $taskId): Task {
            $task = $this->gate->lockAndAuthorize($designer, $taskId, Action::Release);

            $target = $this->workflow->next($task->status, Transition::Release);

            $now = $this->clock->now();
            if (!$this->tasks->unassign($taskId, $designer->id, $task->status, $target, $now)) {
                // The lock makes this impossible; it would mean another code path changed the task unlocked.
                throw new ApiError(409, 'invalid_transition');
            }

            $assignmentId = $this->assignments->closeOpen($taskId, 'released', $now);
            $this->events->record($taskId, TaskEventType::TaskReleased, $designer, ['assignmentId' => $assignmentId]);

            return $this->tasks->find($taskId) ?? throw new RuntimeException('The task just released is gone.');
        });
    }

    private static function heldByAnother(Task $task, User $designer): bool
    {
        return $task->assigneeId !== null && $task->assigneeId !== $designer->id && !$task->status->isTerminal();
    }
}
