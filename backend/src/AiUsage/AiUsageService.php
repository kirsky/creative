<?php

declare(strict_types=1);

namespace App\AiUsage;

use App\History\TaskEventLog;
use App\History\TaskEventType;
use App\Identity\User;
use App\Rounds\RoundRepository;
use App\Shared\Db\Database;
use App\Shared\Http\ApiError;
use App\Tasks\Action;
use App\Tasks\TaskGate;
use App\Tasks\TaskPolicy;

/**
 * The AI credits a designer reports per round (spec section 6): lines of (provider, credits) that
 * are stored with a round when it is submitted and may be replaced by the designer who submitted it
 * until the buyer decides that round. Every change to the lines writes an ai_usage_changed event
 * {round, lines} in the same transaction.
 */
final class AiUsageService
{
    public function __construct(
        private readonly Database $database,
        private readonly TaskGate $gate,
        private readonly TaskPolicy $policy,
        private readonly RoundRepository $rounds,
        private readonly AiUsageRepository $usage,
        private readonly UsageLinesParser $parser,
        private readonly TaskEventLog $events,
    ) {}

    /**
     * Stores the lines of a round that was just inserted and, when there is at least one, records
     * ai_usage_changed {round, lines} (after the caller's round_submitted). Call it inside the
     * caller's transaction; $lines have been validated by UsageLinesParser.
     *
     * @param list<UsageLine> $lines
     */
    public function recordForNewRound(User $designer, int $taskId, int $roundNumber, int $roundId, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $resolved = $this->usage->resolve($lines);
        $this->usage->insert($roundId, $resolved);
        $this->events->record($taskId, TaskEventType::AiUsageChanged, $designer, ['round' => $roundNumber, 'lines' => self::payload($resolved)]);
    }

    /**
     * Replaces all the lines of round $round of a task with $lines (zero lines clears them), and
     * records ai_usage_changed {round, lines} with the new lines. A set equal to the stored one
     * (same providers and amounts, in any order) changes and records nothing.
     *
     * The answers, in the order they are decided:
     *  - 404 not_found: there is no such task, or the actor may not view it, or the task has no such round;
     *  - 403 forbidden: the actor can view the task but is not its assigned designer, or the round was
     *    submitted by another designer;
     *  - 409 invalid_transition: the round is decided already (a task that waits for new work has
     *    only decided rounds: the designer reports usage for the next round when submitting it), or
     *    the task is not in a status that takes usage any more (completed or cancelled);
     *  - 422 validation_failed: bad lines (see UsageLinesParser), field names under `lines`.
     *
     * @param mixed $lines the "lines" of the request body as decoded (null when not sent); it is
     *     checked only after the answers above, which is why it is not typed
     * @throws ApiError see above
     */
    public function replace(User $designer, int $taskId, int $round, mixed $lines): void
    {
        $this->database->transaction(function () use ($designer, $taskId, $round, $lines): void {
            $task = $this->gate->lockAndAuthorize($designer, $taskId, Action::ReportAiUsage);

            $row = $this->rounds->findForUpdate($taskId, $round) ?? throw ApiError::notFound();
            if ($row['submittedBy'] !== $designer->id) {
                throw ApiError::forbidden();
            }

            if ($row['decided'] || !$this->policy->can($designer, Action::ReportAiUsage, $task)) {
                throw new ApiError(409, 'invalid_transition');
            }

            if ($lines === null) {
                throw new ApiError(422, 'validation_failed', [['field' => 'lines', 'code' => 'required', 'params' => []]]);
            }

            $resolved = $this->usage->resolve($this->parser->parse($lines, 'lines'));
            if (self::canonical($resolved) === self::canonical($this->usage->forRound($row['id']))) {
                return;
            }

            $this->usage->deleteAll($row['id']);
            $this->usage->insert($row['id'], $resolved);
            $this->events->record($taskId, TaskEventType::AiUsageChanged, $designer, ['round' => $round, 'lines' => self::payload($resolved)]);
        }, readCommitted: true);
    }

    /**
     * @param list<array{providerId: int, provider: string, credits: string}> $lines
     * @return list<array{provider: string, credits: string}>
     */
    private static function payload(array $lines): array
    {
        return array_map(static fn(array $line): array => ['provider' => $line['provider'], 'credits' => $line['credits']], $lines);
    }

    /**
     * The lines as a sorted list of "providerId:credits", for comparing two sets whatever their order.
     *
     * @param list<array{providerId: int, provider: string, credits: string}> $lines
     * @return list<string>
     */
    private static function canonical(array $lines): array
    {
        $keys = array_map(static fn(array $line): string => $line['providerId'] . ':' . $line['credits'], $lines);
        sort($keys, SORT_STRING);

        return $keys;
    }
}
