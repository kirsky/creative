<?php

declare(strict_types=1);

namespace App\AiUsage;

use App\Shared\Clock;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The time window of an AI usage sum: usage counts when its round was submitted at or after $from,
 * or always when $from is null (all time).
 */
final readonly class Period
{
    private function __construct(public ?DateTimeImmutable $from) {}

    public static function all(): self
    {
        return new self(null);
    }

    /**
     * The last $days days up to now by the Clock: rounds submitted at exactly now minus $days days
     * are in.
     *
     * @throws InvalidArgumentException when $days is not positive
     */
    public static function lastDays(int $days, Clock $clock): self
    {
        if ($days < 1) {
            throw new InvalidArgumentException('A period of days must be at least one day.');
        }

        return new self($clock->now()->modify(sprintf('-%d days', $days)));
    }
}
