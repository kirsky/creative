<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Files\AttachmentKind;
use App\Files\FileStore;
use App\History\TaskEventLog;
use App\History\TaskEventType;
use App\Identity\User;
use App\Shared\Clock;
use App\Shared\Db\Database;
use App\Shared\Http\ApiError;

/**
 * What can be done to tasks: today, creating one.
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
}
