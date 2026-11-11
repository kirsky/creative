<?php

declare(strict_types=1);

namespace App\Tasks;

use JsonSerializable;

/**
 * A task as its page shows it (schema TaskDetail): the card plus the brief. $assignments lists who has
 * worked on the task and $rounds what was handed in, both oldest first. $metrics is part of the contract
 * from the start and stays null until the module that fills it exists.
 */
final readonly class TaskDetail implements JsonSerializable
{
    /**
     * @param list<array<string, int|string>> $references a link {type, url} or a file {type, fileId, name, mime, size}
     * @param list<Assignment> $assignments
     * @param list<RoundView> $rounds
     */
    public function __construct(
        public TaskCard $card,
        public string $description,
        public array $references,
        public array $assignments = [],
        public array $rounds = [],
        public ?object $metrics = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            ...$this->card->jsonSerialize(),
            'description' => $this->description,
            'references' => $this->references,
            'assignments' => $this->assignments,
            'rounds' => $this->rounds,
            'metrics' => $this->metrics,
        ];
    }
}
