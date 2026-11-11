<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Metrics\TaskTimeMetrics;
use JsonSerializable;

/**
 * A task as its page shows it (schema TaskDetail): the card plus the brief. $assignments lists who has
 * worked on the task and $rounds what was handed in, both oldest first. $metrics is the elapsed time
 * the task has spent so far (the totals only; the lists of intervals stay on the server).
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
        public array $assignments,
        public array $rounds,
        public TaskTimeMetrics $metrics,
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
            'metrics' => [
                'queueWaitSeconds' => $this->metrics->queueWaitSeconds,
                'cycleSeconds' => $this->metrics->cycleSeconds,
                'reviewSeconds' => $this->metrics->reviewSeconds,
                'revisionTurnaroundSeconds' => $this->metrics->revisionTurnaroundSeconds,
            ],
        ];
    }
}
