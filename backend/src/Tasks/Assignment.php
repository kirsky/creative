<?php

declare(strict_types=1);

namespace App\Tasks;

use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;

/**
 * One stretch of work by one designer on a task (schema Assignment). Open while $endedAt is null;
 * $endedHow is then null too, and otherwise released, completed or cancelled.
 */
final readonly class Assignment implements JsonSerializable
{
    public function __construct(
        public int $id,
        public Person $designer,
        public DateTimeImmutable $assignedAt,
        public ?DateTimeImmutable $endedAt,
        public ?string $endedHow,
    ) {}

    /**
     * @return array{designer: Person, assignedAt: string, endedAt: ?string, endedHow: ?string}
     */
    public function jsonSerialize(): array
    {
        return [
            'designer' => $this->designer,
            'assignedAt' => self::format($this->assignedAt),
            'endedAt' => $this->endedAt === null ? null : self::format($this->endedAt),
            'endedHow' => $this->endedHow,
        ];
    }

    /** UTC ISO 8601 to the millisecond, as the history shows event times. */
    private static function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
