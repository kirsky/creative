<?php

declare(strict_types=1);

namespace App\Tasks;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The validated part of a brief that an edit names; a field the edit does not mention is null.
 * $geo has no duplicates, $deadline is UTC to the second.
 *
 * values() is the edit in the normalised form that is stored in the audit and compared with the
 * stored brief: codes for choices, a sorted list of codes for geo, the deadline as UTC ISO 8601 with Z.
 */
final readonly class BriefEdit
{
    /** The order the fields of a brief are listed and audited in. */
    public const array FIELDS = ['offer', 'geo', 'creativeType', 'quantity', 'description', 'priority', 'deadline'];

    /**
     * @param list<string>|null $geo
     */
    public function __construct(
        public ?string $offer,
        public ?array $geo,
        public ?CreativeType $creativeType,
        public ?int $quantity,
        public ?string $description,
        public ?Priority $priority,
        public ?DateTimeImmutable $deadline,
    ) {}

    /**
     * The deadline as the brief shows it: UTC, to the second, with a Z.
     */
    public static function deadlineText(DateTimeImmutable $deadline): string
    {
        return $deadline->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * The same edit naming only $fields (names from FIELDS).
     *
     * @param list<string> $fields
     */
    public function only(array $fields): self
    {
        $keep = static fn(string $name): bool => in_array($name, $fields, true);

        return new self(
            $keep('offer') ? $this->offer : null,
            $keep('geo') ? $this->geo : null,
            $keep('creativeType') ? $this->creativeType : null,
            $keep('quantity') ? $this->quantity : null,
            $keep('description') ? $this->description : null,
            $keep('priority') ? $this->priority : null,
            $keep('deadline') ? $this->deadline : null,
        );
    }

    /**
     * The fields the edit names, in the order of FIELDS.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $geo = $this->geo;
        if ($geo !== null) {
            sort($geo);
        }

        $values = [
            'offer' => $this->offer,
            'geo' => $geo,
            'creativeType' => $this->creativeType?->value,
            'quantity' => $this->quantity,
            'description' => $this->description,
            'priority' => $this->priority?->value,
            'deadline' => $this->deadline === null ? null : self::deadlineText($this->deadline),
        ];

        // No field of a brief is ever null, so null means "not named".
        return array_filter($values, static fn(mixed $value): bool => $value !== null);
    }
}
