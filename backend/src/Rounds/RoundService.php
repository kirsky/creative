<?php

declare(strict_types=1);

namespace App\Rounds;

use App\Files\AttachmentKind;
use App\Files\FileStore;
use App\History\TaskEventLog;
use App\History\TaskEventType;
use App\Identity\User;
use App\Shared\Clock;
use App\Shared\Db\Database;
use App\Shared\Http\ApiError;
use App\Tasks\Action;
use App\Tasks\TaskGate;
use App\Tasks\TaskRepository;
use App\Tasks\TaskWorkflow;
use App\Tasks\Transition;

/**
 * Review rounds: the assigned designer hands in deliverables and the task goes to the buyer.
 *
 * A submission is one transaction that starts by locking the task row, so that two submissions of
 * the same task (a double click) cannot both see it open: the status change, the round, its
 * deliverables, the attachment of the files, the event and updated_at are written together or not
 * at all. The status is also changed by a guarded UPDATE that names the status it expects.
 */
final class RoundService
{
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly TaskGate $gate,
        private readonly TaskWorkflow $workflow,
        private readonly TaskRepository $tasks,
        private readonly RoundRepository $rounds,
        private readonly NewRoundParser $parser,
        private readonly FileStore $files,
        private readonly TaskEventLog $events,
    ) {}

    /**
     * Hands in a round: the task goes from In Progress or Revisions Required to Under Review, the
     * round gets the next number (one more than the rounds so far, counted under the row lock), the
     * files become its deliverables and are attached to the task, and round_submitted
     * {round, fileCount} is recorded.
     *
     * The answers, in the order they are decided:
     *  - 404 not_found: there is no such task, or the actor may not view it;
     *  - 403 forbidden: the actor can view it but is not its assigned designer;
     *  - 409 invalid_transition: the task is in a status that takes no submission (a second click
     *    after the first lands here);
     *  - 422 validation_failed: a bad body (see NewRoundParser), or a file that is not the designer's
     *    own or is attached to something already (field `files`, code invalid_choice).
     *
     * @param array<array-key, mixed> $input {note?: string, fileIds: list<int>}
     * @return int the number of the new round
     * @throws ApiError see above
     */
    public function submit(User $designer, int $taskId, array $input): int
    {
        return $this->database->transaction(function () use ($designer, $taskId, $input): int {
            $task = $this->gate->lockAndAuthorize($designer, $taskId, Action::Submit);
            $target = $this->workflow->next($task->status, Transition::Submit);

            $round = $this->parser->parse($input);

            $now = $this->clock->now();
            if (!$this->tasks->changeStatus($taskId, $task->status, $target, $now)) {
                // The lock makes this impossible; it would mean another code path changed the task unlocked.
                throw new ApiError(409, 'invalid_transition');
            }

            $number = $this->rounds->nextNumber($taskId);
            $roundId = $this->rounds->insert($taskId, $number, $designer->id, $now, $round->note);
            foreach ($round->fileIds as $fileId) {
                $this->files->attach($fileId, $taskId, AttachmentKind::Deliverable, $designer);
                $this->rounds->addDeliverable($roundId, $fileId);
            }

            $this->events->record($taskId, TaskEventType::RoundSubmitted, $designer, ['round' => $number, 'fileCount' => count($round->fileIds)]);

            return $number;
        });
    }
}
