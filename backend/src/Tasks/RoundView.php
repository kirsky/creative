<?php

declare(strict_types=1);

namespace App\Tasks;

use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;

/**
 * One round of a task as its page shows it (schema Round): what the designer handed in and what the
 * buyer decided. $decision is null until decided, then revisions_requested or approved. $aiUsage is
 * part of the contract from the start and stays an empty list until the AI usage module fills it.
 */
final readonly class RoundView implements JsonSerializable
{
    /**
     * @param list<array{id: int, name: string, size: int, mime: string}> $files
     */
    public function __construct(
        public int $number,
        public DateTimeImmutable $submittedAt,
        public Person $submittedBy,
        public ?string $note,
        public array $files,
        public ?string $decision,
        public ?string $decisionReason,
        public ?DateTimeImmutable $decidedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'number' => $this->number,
            'submittedAt' => self::format($this->submittedAt),
            'submittedBy' => $this->submittedBy,
            'note' => $this->note,
            'files' => $this->files,
            'decision' => $this->decision,
            'decisionReason' => $this->decisionReason,
            'decidedAt' => $this->decidedAt === null ? null : self::format($this->decidedAt),
            'aiUsage' => [],
        ];
    }

    /** UTC ISO 8601 to the millisecond, as the history shows event times. */
    private static function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
