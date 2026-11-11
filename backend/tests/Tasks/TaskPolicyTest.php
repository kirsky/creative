<?php

declare(strict_types=1);

namespace App\Tests\Tasks;

use App\Identity\Role;
use App\Identity\User;
use App\Tasks\Action;
use App\Tasks\Task;
use App\Tasks\TaskPolicy;
use App\Tasks\TaskStatus;
use App\Tasks\TaskWorkflow;
use App\Tasks\Transition;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec section 3 as a table. The expectations are written out here, per action, and are not computed
 * from TaskPolicy. Every cell of role x relationship x status x action is checked, including
 * combinations the workflow never produces (say, a designer-less task under review), so the policy
 * does not lean on invariants kept somewhere else.
 */
final class TaskPolicyTest extends TestCase
{
    private const int BUYER = 10;
    private const int OTHER_BUYER = 11;
    private const int DESIGNER = 20;
    private const int OTHER_DESIGNER = 21;
    private const int MANAGER = 30;

    /**
     * The actor kinds of the matrix: who is asking, and how they relate to the task.
     * Each entry is [role, actor id, assignee id of the task].
     * The task itself is always created by the buyer BUYER.
     *
     * @return array<string, array{Role, int, ?int}>
     */
    private static function relationships(): array
    {
        return [
            'buyer-own' => [Role::Buyer, self::BUYER, null],
            'buyer-other' => [Role::Buyer, self::OTHER_BUYER, null],
            'designer-unassigned' => [Role::Designer, self::DESIGNER, null],
            'designer-assigned-to-me' => [Role::Designer, self::DESIGNER, self::DESIGNER],
            'designer-assigned-to-other' => [Role::Designer, self::DESIGNER, self::OTHER_DESIGNER],
            'manager' => [Role::Manager, self::MANAGER, null],
        ];
    }

    /**
     * For each action that works on a task: the relationships that may do it, and in which statuses.
     * Everything not listed is forbidden.
     *
     * @return array<string, array<string, list<TaskStatus>>>
     */
    private static function permitted(): array
    {
        $everything = TaskStatus::cases();
        $notFinished = [TaskStatus::New, TaskStatus::InProgress, TaskStatus::UnderReview, TaskStatus::RevisionsRequired];
        $designerWorking = [TaskStatus::InProgress, TaskStatus::RevisionsRequired];

        return [
            // §3 "See the task": own tasks / the New pool + assigned to them / all.
            'ViewTask' => [
                'buyer-own' => $everything,
                'designer-unassigned' => [TaskStatus::New],
                'designer-assigned-to-me' => $everything,
                'designer-assigned-to-other' => [TaskStatus::New],
                'manager' => $everything,
            ],
            // §3 "Edit brief fields of own task (non-terminal)".
            'EditBrief' => ['buyer-own' => $notFinished],
            // §3 "Pick up a New task" (and §4.2: task unassigned).
            'PickUp' => ['designer-unassigned' => [TaskStatus::New]],
            // §3 "Release an assigned task back to New": own assigned, In Progress / Revisions Required.
            'Release' => ['designer-assigned-to-me' => $designerWorking],
            // §3 "Submit a round": own assigned, In Progress / Revisions Required.
            'Submit' => ['designer-assigned-to-me' => $designerWorking],
            // §3 "Report/edit AI usage for a round": own tasks, while the round can still change.
            'ReportAiUsage' => [
                'designer-assigned-to-me' => [TaskStatus::InProgress, TaskStatus::UnderReview, TaskStatus::RevisionsRequired],
            ],
            // §3 "Approve / request revisions": own tasks only, while Under Review.
            'Decide' => ['buyer-own' => [TaskStatus::UnderReview]],
            // §3 "Cancel a task": own tasks, from any non-terminal status.
            'Cancel' => ['buyer-own' => $notFinished],
        ];
    }

    /**
     * @return iterable<string, array{Role, int, ?int, TaskStatus, Action, bool}>
     */
    public static function matrix(): iterable
    {
        $permitted = self::permitted();

        foreach (self::relationships() as $relationship => [$role, $actorId, $assigneeId]) {
            foreach (TaskStatus::cases() as $status) {
                foreach (Action::cases() as $action) {
                    if ($action === Action::CreateTask || $action === Action::ViewDashboard) {
                        continue; // not about a task, see test_create_task_and_dashboard_depend_on_the_role_alone
                    }

                    $allowed = in_array($status, $permitted[$action->name][$relationship] ?? [], true);
                    yield "{$relationship}/{$status->value}/{$action->name}" => [$role, $actorId, $assigneeId, $status, $action, $allowed];
                }
            }
        }
    }

    #[DataProvider('matrix')]
    public function test_matrix(Role $role, int $actorId, ?int $assigneeId, TaskStatus $status, Action $action, bool $expected): void
    {
        $actor = self::user($actorId, $role);
        $task = self::task(status: $status, assigneeId: $assigneeId);

        self::assertSame($expected, new TaskPolicy()->can($actor, $action, $task));
    }

    public function test_the_matrix_covers_every_task_action(): void
    {
        $covered = array_keys(self::permitted());
        sort($covered);
        $taskActions = array_values(array_map(
            static fn(Action $action): string => $action->name,
            array_filter(Action::cases(), static fn(Action $action): bool => $action !== Action::CreateTask && $action !== Action::ViewDashboard),
        ));
        sort($taskActions);

        self::assertSame($taskActions, $covered);
        self::assertCount(8 * 6 * 6, iterator_to_array(self::matrix()), 'six relationships x six statuses x eight actions');
    }

    /**
     * @return array<string, array{Role, bool, bool}>
     */
    public static function rolesWithoutTask(): array
    {
        // role => may create a task, may view the dashboard
        return [
            'buyer' => [Role::Buyer, true, false],
            'designer' => [Role::Designer, false, false],
            'manager' => [Role::Manager, false, true],
        ];
    }

    #[DataProvider('rolesWithoutTask')]
    public function test_create_task_and_dashboard_depend_on_the_role_alone(Role $role, bool $mayCreate, bool $mayViewDashboard): void
    {
        $policy = new TaskPolicy();
        $actor = self::user(self::BUYER, $role);

        self::assertSame($mayCreate, $policy->can($actor, Action::CreateTask));
        self::assertSame($mayViewDashboard, $policy->can($actor, Action::ViewDashboard));

        // A task, if one is passed along, changes nothing.
        $task = self::task(status: TaskStatus::UnderReview, assigneeId: self::DESIGNER);
        self::assertSame($mayCreate, $policy->can($actor, Action::CreateTask, $task));
        self::assertSame($mayViewDashboard, $policy->can($actor, Action::ViewDashboard, $task));
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function everyRole(): array
    {
        return [
            'buyer' => [Role::Buyer],
            'designer' => [Role::Designer],
            'manager' => [Role::Manager],
        ];
    }

    #[DataProvider('everyRole')]
    public function test_actions_on_a_task_are_denied_without_one_and_never_throw(Role $role): void
    {
        $policy = new TaskPolicy();
        $actor = self::user(self::BUYER, $role);

        foreach (Action::cases() as $action) {
            if ($action === Action::CreateTask || $action === Action::ViewDashboard) {
                continue;
            }
            self::assertFalse($policy->can($actor, $action), $action->name);
            self::assertFalse($policy->can($actor, $action, null), $action->name);
        }
    }

    public function test_manager_has_only_view_and_dashboard(): void
    {
        $policy = new TaskPolicy();
        $manager = self::user(self::MANAGER, Role::Manager);
        $granted = [];

        foreach (self::assigneesOfATask() as $label => $assigneeId) {
            foreach (TaskStatus::cases() as $status) {
                $task = self::task(status: $status, assigneeId: $assigneeId);
                foreach (Action::cases() as $action) {
                    if ($policy->can($manager, $action, $task)) {
                        $granted[$action->name] = true;
                    }
                }
                self::assertSame([], $policy->allowedActions($manager, $task), "{$label}/{$status->value}");
            }
        }

        $names = array_keys($granted);
        sort($names);
        self::assertSame(['ViewDashboard', 'ViewTask'], $names);
    }

    public function test_released_designer_cannot_view_task_again_unless_new(): void
    {
        $workflow = new TaskWorkflow();
        $policy = new TaskPolicy();
        $first = self::user(self::DESIGNER, Role::Designer);
        $second = self::user(self::OTHER_DESIGNER, Role::Designer);

        // The first designer picks the task up and works on it.
        $status = $workflow->next(TaskStatus::New, Transition::Pickup);
        $task = self::task(status: $status, assigneeId: $first->id);
        self::assertTrue($policy->can($first, Action::ViewTask, $task));
        self::assertTrue($policy->can($first, Action::Submit, $task));

        // They release it: assignment ends, the task is New, so it is in the pool and visible again.
        $status = $workflow->next($status, Transition::Release);
        $released = self::task(status: $status, assigneeId: null);
        self::assertSame(TaskStatus::New, $released->status);
        self::assertTrue($policy->can($first, Action::ViewTask, $released), 'visible because it is New, not because they once held it');
        self::assertTrue($policy->can($first, Action::PickUp, $released));

        // The second designer takes it. From here the first designer has no claim on it at all.
        $status = $workflow->next($status, Transition::Pickup);
        $taken = self::task(status: $status, assigneeId: $second->id);
        foreach ([
            TaskStatus::InProgress,
            TaskStatus::UnderReview,
            TaskStatus::RevisionsRequired,
            TaskStatus::Completed,
            TaskStatus::Cancelled,
        ] as $later) {
            $task = self::task(status: $later, assigneeId: $second->id);
            self::assertFalse($policy->can($first, Action::ViewTask, $task), $later->value);
            self::assertSame([], $policy->allowedActions($first, $task), $later->value);
            self::assertTrue($policy->can($second, Action::ViewTask, $task), $later->value);
        }
        self::assertSame(TaskStatus::InProgress, $taken->status);

        // The second designer releases it too: the pool again, and the first designer sees it again.
        $back = self::task(status: $workflow->next($taken->status, Transition::Release), assigneeId: null);
        self::assertTrue($policy->can($first, Action::ViewTask, $back));
        self::assertTrue($policy->can($second, Action::ViewTask, $back));
    }

    public function test_ownership_needs_the_buyer_role_and_the_creator_id(): void
    {
        $policy = new TaskPolicy();
        $task = self::task(status: TaskStatus::UnderReview, assigneeId: self::DESIGNER);

        // Same id as the creator, wrong role.
        foreach ([Role::Designer, Role::Manager] as $role) {
            $impostor = self::user(self::BUYER, $role);
            foreach ([Action::EditBrief, Action::Decide, Action::Cancel] as $action) {
                self::assertFalse($policy->can($impostor, $action, $task), "{$role->value}/{$action->name}");
            }
        }

        // Same id as the assignee, wrong role.
        foreach ([Role::Buyer, Role::Manager] as $role) {
            $impostor = self::user(self::DESIGNER, $role);
            foreach ([Action::Release, Action::Submit, Action::ReportAiUsage] as $action) {
                self::assertFalse($policy->can($impostor, $action, $task), "{$role->value}/{$action->name}");
            }
        }

        // A designer's id equal to the creator's does not make the designer the owner either.
        $designerWithCreatorsId = self::user(self::BUYER, Role::Designer);
        self::assertFalse($policy->can($designerWithCreatorsId, Action::ViewTask, $task));
    }

    public function test_a_pickup_needs_an_unassigned_task(): void
    {
        $policy = new TaskPolicy();
        $designer = self::user(self::DESIGNER, Role::Designer);

        self::assertTrue($policy->can($designer, Action::PickUp, self::task(status: TaskStatus::New, assigneeId: null)));
        self::assertFalse($policy->can($designer, Action::PickUp, self::task(status: TaskStatus::New, assigneeId: self::OTHER_DESIGNER)));
        self::assertFalse($policy->can($designer, Action::PickUp, self::task(status: TaskStatus::New, assigneeId: self::DESIGNER)));
    }

    /**
     * Hand-written, so the names, their order and the Decide split are pinned independently.
     * The order is the one the API sends: pick_up, release, submit, edit_brief, report_ai_usage,
     * approve, request_revisions, cancel.
     *
     * @return array<string, array{Role, int, ?int, TaskStatus, list<string>}>
     */
    public static function allowedActionNames(): array
    {
        return [
            'buyer-own/new' => [Role::Buyer, self::BUYER, null, TaskStatus::New, ['edit_brief', 'cancel']],
            'buyer-own/in_progress' => [Role::Buyer, self::BUYER, self::DESIGNER, TaskStatus::InProgress, ['edit_brief', 'cancel']],
            'buyer-own/under_review' => [Role::Buyer, self::BUYER, self::DESIGNER, TaskStatus::UnderReview, ['edit_brief', 'approve', 'request_revisions', 'cancel']],
            'buyer-own/revisions_required' => [Role::Buyer, self::BUYER, self::DESIGNER, TaskStatus::RevisionsRequired, ['edit_brief', 'cancel']],
            'buyer-own/completed' => [Role::Buyer, self::BUYER, self::DESIGNER, TaskStatus::Completed, []],
            'buyer-own/cancelled' => [Role::Buyer, self::BUYER, null, TaskStatus::Cancelled, []],
            'buyer-other/new' => [Role::Buyer, self::OTHER_BUYER, null, TaskStatus::New, []],
            'buyer-other/under_review' => [Role::Buyer, self::OTHER_BUYER, self::DESIGNER, TaskStatus::UnderReview, []],
            'designer-unassigned/new' => [Role::Designer, self::DESIGNER, null, TaskStatus::New, ['pick_up']],
            'designer-unassigned/cancelled' => [Role::Designer, self::DESIGNER, null, TaskStatus::Cancelled, []],
            'designer-assigned-to-me/in_progress' => [Role::Designer, self::DESIGNER, self::DESIGNER, TaskStatus::InProgress, ['release', 'submit', 'report_ai_usage']],
            'designer-assigned-to-me/under_review' => [Role::Designer, self::DESIGNER, self::DESIGNER, TaskStatus::UnderReview, ['report_ai_usage']],
            'designer-assigned-to-me/revisions_required' => [Role::Designer, self::DESIGNER, self::DESIGNER, TaskStatus::RevisionsRequired, ['release', 'submit', 'report_ai_usage']],
            'designer-assigned-to-me/completed' => [Role::Designer, self::DESIGNER, self::DESIGNER, TaskStatus::Completed, []],
            'designer-assigned-to-me/cancelled' => [Role::Designer, self::DESIGNER, self::DESIGNER, TaskStatus::Cancelled, []],
            'designer-assigned-to-other/in_progress' => [Role::Designer, self::DESIGNER, self::OTHER_DESIGNER, TaskStatus::InProgress, []],
            'designer-assigned-to-other/under_review' => [Role::Designer, self::DESIGNER, self::OTHER_DESIGNER, TaskStatus::UnderReview, []],
            'manager/new' => [Role::Manager, self::MANAGER, null, TaskStatus::New, []],
            'manager/under_review' => [Role::Manager, self::MANAGER, self::DESIGNER, TaskStatus::UnderReview, []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('allowedActionNames')]
    public function test_allowed_actions(Role $role, int $actorId, ?int $assigneeId, TaskStatus $status, array $expected): void
    {
        $actor = self::user($actorId, $role);
        $task = self::task(status: $status, assigneeId: $assigneeId);

        self::assertSame($expected, new TaskPolicy()->allowedActions($actor, $task));
    }

    /**
     * The matrix rows for the actions that allowedActions() names (everything except ViewTask).
     *
     * @return iterable<string, array{Role, int, ?int, TaskStatus, Action, bool}>
     */
    public static function listedActionsMatrix(): iterable
    {
        foreach (self::matrix() as $key => $row) {
            if ($row[4] !== Action::ViewTask) {
                yield $key => $row;
            }
        }
    }

    #[DataProvider('listedActionsMatrix')]
    public function test_allowed_actions_agree_with_the_matrix(Role $role, int $actorId, ?int $assigneeId, TaskStatus $status, Action $action, bool $expected): void
    {
        $policy = new TaskPolicy();
        $actor = self::user($actorId, $role);
        $task = self::task(status: $status, assigneeId: $assigneeId);

        $names = match ($action) {
            Action::Decide => ['approve', 'request_revisions'],
            Action::PickUp => ['pick_up'],
            Action::Release => ['release'],
            Action::Submit => ['submit'],
            Action::EditBrief => ['edit_brief'],
            Action::ReportAiUsage => ['report_ai_usage'],
            Action::Cancel => ['cancel'],
            Action::CreateTask, Action::ViewTask, Action::ViewDashboard => throw new LogicException('not a listed action'),
        };

        foreach ($names as $name) {
            self::assertSame($expected, in_array($name, $policy->allowedActions($actor, $task), true), $name);
        }
    }

    /**
     * The assignee a task can carry, labelled.
     *
     * @return array<string, ?int>
     */
    private static function assigneesOfATask(): array
    {
        return [
            'unassigned' => null,
            'assigned-to-a-designer' => self::DESIGNER,
        ];
    }

    private static function user(int $id, Role $role): User
    {
        return new User($id, "user{$id}", "User {$id}", $role, 'en', null);
    }

    private static function task(TaskStatus $status, ?int $assigneeId = null): Task
    {
        return new Task(
            id: 1,
            createdBy: self::BUYER,
            assigneeId: $assigneeId,
            status: $status,
            deadlineAt: new DateTimeImmutable('2026-12-01 12:00:00 UTC'),
        );
    }
}
