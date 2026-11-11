<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\MutableClock;
use App\Shared\SystemClock;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    public function test_mutable_clock_advances_in_utc(): void
    {
        // 00:30 in Berlin on the night clocks change is 23:30 UTC the day before.
        $clock = new MutableClock(new DateTimeImmutable('2026-03-29 00:30:00', new DateTimeZone('Europe/Berlin')));

        self::assertSame('UTC', $clock->now()->getTimezone()->getName());
        self::assertSame('2026-03-28T23:30:00+00:00', $clock->now()->format('c'));

        $clock->advance('PT2H');
        self::assertSame('2026-03-29T01:30:00+00:00', $clock->now()->format('c'));

        $clock->advance('+1 day');
        self::assertSame('2026-03-30T01:30:00+00:00', $clock->now()->format('c'));

        $clock->advance('-90 minutes');
        self::assertSame('2026-03-30T00:00:00+00:00', $clock->now()->format('c'));
        self::assertSame('UTC', $clock->now()->getTimezone()->getName());
    }

    public function test_mutable_clock_does_not_move_on_its_own(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('UTC')));

        self::assertEquals($clock->now(), $clock->now());
    }

    public function test_mutable_clock_can_be_set(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('UTC')));
        $clock->set(new DateTimeImmutable('2026-06-01 09:00:00', new DateTimeZone('America/New_York')));

        self::assertSame('2026-06-01T13:00:00+00:00', $clock->now()->format('c'));
    }

    public function test_mutable_clock_rejects_an_unparseable_interval(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('UTC')));

        $this->expectException(InvalidArgumentException::class);
        $clock->advance('soonish');
    }

    public function test_system_clock_is_utc_and_current(): void
    {
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $now = (new SystemClock())->now();
        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual($after, $now);
    }
}
