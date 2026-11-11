<?php

declare(strict_types=1);

namespace App\Tasks;

use JsonSerializable;

/**
 * A task as its page shows it (schema TaskDetail): the card plus the brief. $assignments, $rounds and
 * $metrics are part of the contract from the start and stay empty (and null) until the modules that
 * fill them exist.
 */
final readonly class TaskDetail implements JsonSerializable
{
    /**
     * @param list<array<string, int|string>> $references a link {type, url} or a file {type, fileId, name, mime, size}
     * @param list<never> $assignments
     * @param list<never> $rounds
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
