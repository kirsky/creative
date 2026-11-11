<?php

declare(strict_types=1);

namespace App\Tasks;

use DateTimeImmutable;
use JsonSerializable;

/**
 * A task as the board shows it (schema TaskCard). $roundCount, $creditsTotal and $isReturning come
 * from the round summary; $allowedActions are the buttons the viewer gets, from TaskPolicy.
 */
final readonly class TaskCard implements JsonSerializable
{
    /**
     * @param list<string> $geo
     * @param list<string> $allowedActions
     */
    public function __construct(
        public int $id,
        public string $offer,
        public array $geo,
        public CreativeType $creativeType,
        public int $quantity,
        public Priority $priority,
        public DateTimeImmutable $deadlineAt,
        public TaskStatus $status,
        public Person $createdBy,
        public ?Person $assignee,
        public int $roundCount,
        public string $creditsTotal,
        public bool $isOverdue,
        public bool $isReturning,
        public array $allowedActions,
    ) {}

    /**
     * @return array{id: int, offer: string, geo: list<string>, creativeType: string, quantity: int, priority: string, deadlineAt: string, status: string, createdBy: Person, assignee: Person|null, roundCount: int, creditsTotal: string, isOverdue: bool, isReturning: bool, allowedActions: list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'offer' => $this->offer,
            'geo' => $this->geo,
            'creativeType' => $this->creativeType->value,
            'quantity' => $this->quantity,
            'priority' => $this->priority->value,
            'deadlineAt' => $this->deadlineAt->format('Y-m-d\TH:i:s\Z'),
            'status' => $this->status->value,
            'createdBy' => $this->createdBy,
            'assignee' => $this->assignee,
            'roundCount' => $this->roundCount,
            'creditsTotal' => $this->creditsTotal,
            'isOverdue' => $this->isOverdue,
            'isReturning' => $this->isReturning,
            'allowedActions' => $this->allowedActions,
        ];
    }
}
