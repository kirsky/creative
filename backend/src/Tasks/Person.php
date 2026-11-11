<?php

declare(strict_types=1);

namespace App\Tasks;

use JsonSerializable;

/**
 * A user as a task shows them: who created it, who works on it. Nothing else of the account.
 */
final readonly class Person implements JsonSerializable
{
    public function __construct(
        public int $id,
        public string $displayName,
    ) {}

    /**
     * @return array{id: int, displayName: string}
     */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'displayName' => $this->displayName];
    }
}
