<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * One thing that happens to a demo task: who does it, how long before "now" (in seconds), and what
 * they pass to the service. The constructors are the vocabulary of DemoScenario.
 */
final readonly class PlannedStep
{
    /**
     * @param array<string, mixed> $data what the service call needs
     */
    private function __construct(
        public StepKind $kind,
        public int $secondsAgo,
        public string $who,
        public array $data = [],
    ) {}

    /**
     * @param array<string, string> $changes brief fields to change (field => new value)
     */
    public static function edit(int $secondsAgo, string $buyer, array $changes): self
    {
        return new self(StepKind::Edit, $secondsAgo, $buyer, ['changes' => $changes]);
    }

    public static function pickUp(int $secondsAgo, string $designer): self
    {
        return new self(StepKind::PickUp, $secondsAgo, $designer);
    }

    public static function release(int $secondsAgo, string $designer): self
    {
        return new self(StepKind::Release, $secondsAgo, $designer);
    }

    /**
     * @param list<string> $files names of the placeholder images handed in
     * @param list<array{string, string}> $aiUsage provider and credits ("120.00") per line
     */
    public static function submit(int $secondsAgo, string $designer, array $files, array $aiUsage = [], ?string $note = null): self
    {
        return new self(StepKind::Submit, $secondsAgo, $designer, ['files' => $files, 'aiUsage' => $aiUsage, 'note' => $note]);
    }

    public static function requestRevisions(int $secondsAgo, string $buyer, string $reason): self
    {
        return new self(StepKind::RequestRevisions, $secondsAgo, $buyer, ['reason' => $reason]);
    }

    public static function approve(int $secondsAgo, string $buyer, ?string $note = null): self
    {
        return new self(StepKind::Approve, $secondsAgo, $buyer, ['note' => $note]);
    }

    public static function cancel(int $secondsAgo, string $buyer, ?string $reason = null): self
    {
        return new self(StepKind::Cancel, $secondsAgo, $buyer, ['reason' => $reason]);
    }

    /**
     * The creation of the task, which every task starts with (see PlannedTask).
     */
    public static function create(int $secondsAgo, string $buyer): self
    {
        return new self(StepKind::Create, $secondsAgo, $buyer);
    }
}
