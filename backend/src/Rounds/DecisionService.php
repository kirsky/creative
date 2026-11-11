<?php

declare(strict_types=1);

namespace App\Rounds;

use App\History\TaskEventLog;
use App\History\TaskEventType;
use App\Identity\User;
use App\Shared\Clock;
use App\Shared\Db\Database;
use App\Shared\Http\ApiError;
use App\Shared\Validation\FreeText;
use App\Tasks\Action;
use App\Tasks\AssignmentRepository;
use App\Tasks\TaskGate;
use App\Tasks\TaskRepository;
use App\Tasks\TaskWorkflow;
use App\Tasks\Transition;
use RuntimeException;

/**
 * The buyer's decision on the round that waits for review: approve it, or send it back.
 *
 * Like a submission, a decision is one transaction that starts by locking the task row, so that a
 * double click, or Approve racing Cancel, finds the task already moved on and is told so (409). The
 * decision on the round, the status change (a guarded UPDATE that names the status it expects), the
 * closing of the assignment (approval only), the event and updated_at are written together or not at all.
 */
final class DecisionService
{
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly TaskGate $gate,
        private readonly TaskWorkflow $workflow,
        private readonly TaskRepository $tasks,
        private readonly RoundRepository $rounds,
        private readonly AssignmentRepository $assignments,
        private readonly FreeText $text,
        private readonly TaskEventLog $events,
    ) {}

    /**
     * Approves the latest round: the task becomes Completed, the round is stored as approved with the
     * note (null when there is none), the open assignment is closed as completed and
     * task_approved {round, note} is recorded. The designer stays the task's assignee.
     *
     * The answers, in the order they are decided:
     *  - 404 not_found: there is no such task, or the actor may not view it;
     *  - 403 forbidden: the actor can view it but is not the buyer who created it;
     *  - 409 invalid_transition: the task is not under review (this is what a second click gets);
     *  - 422 validation_failed: $note is not a string or is longer than 2000 characters.
     *
     * @param mixed $note the "note" of the request body as decoded (null when not sent); it is
     *     checked only after the answers above, which is why it is not typed
     * @throws ApiError see above
     * @throws RuntimeException when the task is under review but has no undecided latest round
     */
    public function approve(User $buyer, int $taskId, mixed $note): void
    {
        $this->database->transaction(function () use ($buyer, $taskId, $note): void {
            $task = $this->gate->lockAndAuthorize($buyer, $taskId, Action::Decide);
            $target = $this->workflow->next($task->status, Transition::Approve);
            $note = $this->text->optional($note, 'note');

            $round = $this->roundToDecide($taskId);
            $now = $this->clock->now();
            if (!$this->tasks->changeStatus($taskId, $task->status, $target, $now) || !$this->rounds->decide($round['id'], 'approved', $note, $now)) {
                // The locks make this impossible; it would mean another code path changed the task unlocked.
                throw new ApiError(409, 'invalid_transition');
            }

            $this->assignments->closeOpen($taskId, 'completed', $now);
            $this->events->record($taskId, TaskEventType::TaskApproved, $buyer, ['round' => $round['number'], 'note' => $note]);
        });
    }

    /**
     * Sends the latest round back: the task becomes Revisions Required, the round is stored as
     * revisions_requested with the reason, and revisions_requested {round, reason} is recorded. The
     * assignment stays open and the designer stays assigned.
     *
     * The answers are those of approve(), with 422 validation_failed for a reason that is missing,
     * blank (required), not a string (invalid) or longer than 2000 characters (too_long).
     *
     * @param mixed $reason the "reason" of the request body as decoded (null when not sent); it is
     *     checked only after the permission and the status, which is why it is not typed
     * @throws ApiError see above
     * @throws RuntimeException when the task is under review but has no undecided latest round
     */
    public function requestRevisions(User $buyer, int $taskId, mixed $reason): void
    {
        $this->database->transaction(function () use ($buyer, $taskId, $reason): void {
            $task = $this->gate->lockAndAuthorize($buyer, $taskId, Action::Decide);
            $target = $this->workflow->next($task->status, Transition::RequestRevisions);
            $reason = $this->text->required($reason, 'reason');

            $round = $this->roundToDecide($taskId);
            $now = $this->clock->now();
            if (!$this->tasks->changeStatus($taskId, $task->status, $target, $now) || !$this->rounds->decide($round['id'], 'revisions_requested', $reason, $now)) {
                // The locks make this impossible; it would mean another code path changed the task unlocked.
                throw new ApiError(409, 'invalid_transition');
            }

            $this->events->record($taskId, TaskEventType::RevisionsRequested, $buyer, ['round' => $round['number'], 'reason' => $reason]);
        });
    }

    /**
     * The latest round of a task that is under review, which must exist and be undecided: submitting
     * is what moves a task there, and deciding is what moves it on.
     *
     * @return array{id: int, number: int, decided: bool}
     * @throws RuntimeException when the data says otherwise, which is a bug or a hand-edited database
     *     and not something to guess around
     */
    private function roundToDecide(int $taskId): array
    {
        $round = $this->rounds->latestForUpdate($taskId)
            ?? throw new RuntimeException(sprintf('Task %d is under review but has no round.', $taskId));

        return !$round['decided']
            ? $round
            : throw new RuntimeException(sprintf('Task %d is under review but its latest round (%d) is decided already.', $taskId, $round['number']));
    }
}
