<?php

declare(strict_types=1);

namespace App\Shared;

use DateInterval;
use DateMalformedIntervalStringException;
use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A clock that only moves when told to; for tests and the demo scenario.
 */
final class MutableClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable $start)
    {
        $this->set($start);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable $t): void
    {
        $this->now = $t->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Moves the clock by an ISO 8601 duration ("PT90M", "P1D") or a relative
     * date string ("+90 minutes", "-1 day"). Calendar arithmetic happens in UTC.
     */
    public function advance(string $interval): void
    {
        try {
            if (str_starts_with($interval, 'P')) {
                $this->now = $this->now->add(new DateInterval($interval));

                return;
            }

            $this->now = $this->now->modify($interval);
        } catch (DateMalformedIntervalStringException|DateMalformedStringException $e) {
            throw new InvalidArgumentException(sprintf('Cannot advance the clock by "%s".', $interval), 0, $e);
        }
    }
}
