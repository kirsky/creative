<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Identity\Role;
use App\Identity\User;

/**
 * Who may do what (spec section 3). Pure: it answers from the actor, the action and the task it is
 * given, and nothing else. A task the actor may not see is a 404 and a task they may see but not act
 * on is a 403; telling those apart is the caller's job, using ViewTask first.
 *
 * Whether the move is legal in the task's status comes from TaskWorkflow, so the status rules exist
 * once. Manager is read-only: ViewTask and ViewDashboard are all they get.
 */
final class TaskPolicy
{
    /**
     * The names allowedActions() reports, in the order it reports them, and the action behind each.
     * Approving and requesting revisions are one decision as far as permissions go.
     *
     * @var list<array{string, Action}>
     */
    private const array LISTED = [
        ['pick_up', Action::PickUp],
        ['release', Action::Release],
        ['submit', Action::Submit],
        ['edit_brief', Action::EditBrief],
        ['report_ai_usage', Action::ReportAiUsage],
        ['approve', Action::Decide],
        ['request_revisions', Action::Decide],
        ['cancel', Action::Cancel],
    ];

    /**
     * @param TaskWorkflow $workflow the source of which status allows which move
     */
    public function __construct(
        private readonly TaskWorkflow $workflow = new TaskWorkflow(),
    ) {}

    /**
     * May $actor do $action? $task is needed for every action but CreateTask and ViewDashboard;
     * without it those answer false instead of throwing.
     *
     * That is entitled() and whether the task's status takes the move.
     */
    public function can(User $actor, Action $action, ?Task $task = null): bool
    {
        return $this->entitled($actor, $action, $task) && $this->statusAllows($action, $task);
    }

    /**
     * Is $actor the kind of user who does $action to $task, whatever its status is? The role and the
     * relation to the task (its creating buyer, its assigned designer) and nothing else. Callers that
     * must tell a refusal (403 forbidden) from a conflict of status (409) ask this first and let
     * TaskWorkflow, or their own rule, decide the status; see TaskGate.
     */
    public function entitled(User $actor, Action $action, ?Task $task = null): bool
    {
        return match ($action) {
            Action::CreateTask => $actor->role === Role::Buyer,
            Action::ViewDashboard => $actor->role === Role::Manager,
            Action::ViewTask => $task !== null && $this->canView($actor, $task),
            Action::PickUp => $task !== null && $actor->role === Role::Designer,
            Action::Release, Action::Submit, Action::ReportAiUsage => $task !== null && $this->isAssignedDesigner($actor, $task),
            Action::EditBrief, Action::Decide, Action::Cancel => $task !== null && $this->isCreatingBuyer($actor, $task),
        };
    }

    /**
     * Does the status of $task (and, for a pick-up, its lack of an assignee) take $action? True for
     * the actions that do not depend on it.
     */
    private function statusAllows(Action $action, ?Task $task): bool
    {
        return match ($action) {
            Action::CreateTask, Action::ViewDashboard, Action::ViewTask => true,
            Action::PickUp => $task !== null
                && $task->assigneeId === null
                && $this->workflow->allows($task->status, Transition::Pickup),
            Action::Release => $task !== null && $this->workflow->allows($task->status, Transition::Release),
            Action::Submit => $task !== null && $this->workflow->allows($task->status, Transition::Submit),
            // A coarse gate: the statuses in which a round is being prepared (In Progress, Revisions
            // Required) or waits for the buyer (Under Review). Whether one particular round can still
            // be edited, that is, has not been decided yet, is checked where the lines are written.
            Action::ReportAiUsage => $task !== null
                && in_array($task->status, [TaskStatus::InProgress, TaskStatus::UnderReview, TaskStatus::RevisionsRequired], true),
            Action::EditBrief => $task !== null && !$task->status->isTerminal(),
            Action::Decide => $task !== null && $this->workflow->allows($task->status, Transition::Approve),
            Action::Cancel => $task !== null && $this->workflow->allows($task->status, Transition::Cancel),
        };
    }

    /**
     * The buttons $actor gets on $task, as the names the API sends, in a fixed order. Empty when they
     * cannot see the task at all.
     *
     * @return list<string>
     */
    public function allowedActions(User $actor, Task $task): array
    {
        if (!$this->can($actor, Action::ViewTask, $task)) {
            return [];
        }

        $names = [];
        foreach (self::LISTED as [$name, $action]) {
            if ($this->can($actor, $action, $task)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * A buyer sees what they created, a designer sees the New pool and what is assigned to them, a
     * manager sees everything. A designer who released a task and saw it taken by someone else has
     * no claim left: assigneeId no longer names them.
     */
    private function canView(User $actor, Task $task): bool
    {
        return match ($actor->role) {
            Role::Buyer => $this->isCreatingBuyer($actor, $task),
            Role::Designer => $task->status === TaskStatus::New || $task->assigneeId === $actor->id,
            Role::Manager => true,
        };
    }

    private function isCreatingBuyer(User $actor, Task $task): bool
    {
        return $actor->role === Role::Buyer && $task->createdBy === $actor->id;
    }

    private function isAssignedDesigner(User $actor, Task $task): bool
    {
        return $actor->role === Role::Designer && $task->assigneeId === $actor->id;
    }
}
