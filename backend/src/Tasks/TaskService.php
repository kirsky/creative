<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Files\AttachmentKind;
use App\Files\FileStore;
use App\History\FieldAuditLog;
use App\History\TaskEventLog;
use App\History\TaskEventType;
use App\Identity\Role;
use App\Identity\User;
use App\Shared\Clock;
use App\Shared\Db\Database;
use App\Shared\Http\ApiError;
use RuntimeException;

/**
 * What can be done to tasks: creating one and editing its brief.
 */
final class TaskService
{
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly TaskPolicy $policy,
        private readonly NewTaskParser $parser,
        private readonly TaskRepository $tasks,
        private readonly FileStore $files,
        private readonly TaskEventLog $events,
        private readonly FieldAuditLog $audit,
    ) {}

    /**
     * Creates a New task from the JSON of a brief.
     *
     * The task, its geo, its references, the attachment of the reference files and the task_created
     * event (an empty payload) are stored in one transaction: a failure anywhere leaves nothing.
     *
     * @param array<array-key, mixed> $input offer, geo, creativeType, quantity, description, priority,
     *     deadline (ISO 8601 with a zone, after now) and optionally references
     * @throws ApiError 403 forbidden unless the actor is a buyer; 422 validation_failed for a bad
     *     input, or for a reference file that is not the actor's or is attached already
     */
    public function create(User $actor, array $input): Task
    {
        if (!$this->policy->can($actor, Action::CreateTask)) {
            throw ApiError::forbidden();
        }

        $new = $this->parser->parse($input);
        $now = $this->clock->now();

        return $this->database->transaction(function () use ($actor, $new, $now): Task {
            $task = $this->tasks->insert($actor, $new, $now);

            foreach ($new->references as $reference) {
                if ($reference->url !== null) {
                    $this->tasks->addUrl($task->id, $reference->url);
                } else {
                    $fileId = $reference->requireFileId();
                    $this->files->attach($fileId, $task->id, AttachmentKind::Reference, $actor);
                    $this->tasks->addFile($task->id, $fileId);
                }
            }

            $this->events->record($task->id, TaskEventType::TaskCreated, $actor);

            return $task;
        });
    }

    /**
     * Edits the brief of a task: the offer, geo, creativeType, quantity, description, priority and
     * deadline named in $changes, each under the same rules as on creation. References cannot be
     * edited and are an unknown field.
     *
     * Only fields whose normalised value differs from the stored one are written. They are audited
     * as one brief_edited event, with the payload {changes: [{field, old, new}]} in the order of the
     * brief, and as the same rows of FieldAuditLog. The update (and updated_at), the event and the
     * audit rows are one transaction. When nothing differs nothing is written, not even updated_at,
     * and the task is returned as it is. The deadline may be sent back as stored even when it is
     * past; any other deadline must be in the future.
     *
     * @param array<array-key, mixed> $changes the JSON body; at least one field
     * @throws ApiError 404 not_found for a task that does not exist or that $actor may not view;
     *     403 forbidden unless $actor is the buyer who created it; 409 task_not_editable once it is
     *     completed or cancelled; 422 validation_failed for bad input (checked after the permission)
     */
    public function editBrief(User $actor, int $taskId, array $changes): Task
    {
        return $this->database->transaction(function () use ($actor, $taskId, $changes): Task {
            // Locked, so that a concurrent cancellation cannot slip in between the check and the update.
            $task = $this->tasks->findForUpdate($taskId);
            if ($task === null || !$this->policy->can($actor, Action::ViewTask, $task)) {
                throw ApiError::notFound();
            }

            if (!$this->policy->can($actor, Action::EditBrief, $task)) {
                // Only the creating buyer may edit; of them, a finished task is a conflict, not a refusal.
                throw $actor->role === Role::Buyer && $actor->id === $task->createdBy
                    ? new ApiError(409, 'task_not_editable')
                    : ApiError::forbidden();
            }

            $edit = $this->parser->parseEdit($changes, $task->deadlineAt);
            $stored = $this->tasks->brief($taskId);

            $diff = [];
            foreach ($edit->values() as $field => $new) {
                if ($new !== $stored[$field]) {
                    $diff[] = ['field' => $field, 'old' => $stored[$field], 'new' => $new];
                }
            }
            if ($diff === []) {
                return $task;
            }

            $this->tasks->updateBrief($taskId, $edit->only(array_column($diff, 'field')), $this->clock->now());
            $eventId = $this->events->record($taskId, TaskEventType::BriefEdited, $actor, ['changes' => $diff]);
            $this->audit->record($eventId, $diff);

            return $this->tasks->find($taskId) ?? throw new RuntimeException('The task just edited is gone.');
        });
    }
}
