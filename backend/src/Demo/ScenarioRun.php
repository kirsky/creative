<?php

declare(strict_types=1);

namespace App\Demo;

use App\Identity\User;
use App\Rounds\DecisionService;
use App\Rounds\RoundService;
use App\Tasks\AssignmentService;
use App\Tasks\TaskService;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * Performs the steps of the scenario, one call of a real service each, as the user the step names.
 * The clock the services read has been set to the time of the step by the caller.
 */
final class ScenarioRun
{
    /** @var array<string, int> task id by offer (offers are unique in the scenario) */
    private array $taskIds = [];

    /**
     * @param array<string, User> $accounts by username
     */
    public function __construct(
        private readonly DateTimeImmutable $now,
        private readonly array $accounts,
        private readonly TaskService $tasks,
        private readonly AssignmentService $assignments,
        private readonly RoundService $rounds,
        private readonly DecisionService $decisions,
        private readonly PlaceholderFiles $files,
    ) {}

    public function perform(PlannedTask $task, PlannedStep $step): void
    {
        $actor = $this->accounts[$step->who] ?? throw new LogicException(sprintf('The scenario names an unknown account "%s".', $step->who));

        if ($step->kind === StepKind::Create) {
            $this->taskIds[$task->offer] = $this->create($actor, $task);

            return;
        }

        $id = $this->taskIds[$task->offer] ?? throw new LogicException(sprintf('"%s" is used before it is created.', $task->offer));

        match ($step->kind) {
            StepKind::Edit => $this->tasks->editBrief($actor, $id, self::map($step->data['changes'] ?? null)),
            StepKind::PickUp => $this->assignments->pickUp($actor, $id),
            StepKind::Release => $this->assignments->release($actor, $id),
            StepKind::Submit => $this->submit($actor, $id, $step),
            StepKind::RequestRevisions => $this->decisions->requestRevisions($actor, $id, $step->data['reason'] ?? null),
            StepKind::Approve => $this->decisions->approve($actor, $id, $step->data['note'] ?? null),
            StepKind::Cancel => $this->tasks->cancel($actor, $id, $step->data['reason'] ?? null),
        };
    }

    private function create(User $buyer, PlannedTask $task): int
    {
        $references = [];
        foreach ($task->referenceUrls as $url) {
            $references[] = ['type' => 'url', 'url' => $url];
        }
        foreach ($task->referenceFiles as $name) {
            $references[] = ['type' => 'file', 'fileId' => $this->files->store($buyer, $name)->id];
        }

        $input = [
            'offer' => $task->offer,
            'geo' => $task->geo,
            'creativeType' => $task->creativeType,
            'quantity' => $task->quantity,
            'description' => $task->description,
            'priority' => $task->priority,
            'deadline' => $this->now->modify(sprintf('%+d seconds', $task->deadlineSecondsFromNow))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
        ];
        if ($references !== []) {
            $input['references'] = $references;
        }

        return $this->tasks->create($buyer, $input)->id;
    }

    private function submit(User $designer, int $taskId, PlannedStep $step): void
    {
        $names = $step->data['files'] ?? [];
        $lines = $step->data['aiUsage'] ?? [];
        $note = $step->data['note'] ?? null;
        if (!is_array($names) || !is_array($lines)) {
            throw new LogicException('A submit step needs files and AI usage lists.');
        }

        $input = [
            'fileIds' => array_map(
                fn(mixed $name): int => $this->files->store($designer, is_string($name) ? $name : throw new LogicException('A file name is not a string.'))->id,
                $names,
            ),
        ];
        if (is_string($note)) {
            $input['note'] = $note;
        }
        if ($lines !== []) {
            $input['aiUsage'] = array_map(
                static fn(mixed $line): array => is_array($line) ? ['provider' => $line[0], 'credits' => $line[1]] : throw new LogicException('An AI usage line is not a pair.'),
                $lines,
            );
        }

        $this->rounds->submit($designer, $taskId, $input);
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        if (!is_array($value)) {
            throw new LogicException('Expected a map.');
        }

        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }
}
