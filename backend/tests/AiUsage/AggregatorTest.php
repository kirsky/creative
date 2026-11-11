<?php

declare(strict_types=1);

namespace App\Tests\AiUsage;

use App\AiUsage\AiUsageAggregator;
use App\AiUsage\Period;
use App\Identity\User;
use App\Tasks\TaskStatus;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The sums the manager dashboard shows: by provider, by designer, designer x provider, over all time
 * or a period counted from the time the rounds were submitted. Amounts are DECIMAL in SQL and
 * strings in PHP, so the sums are exact.
 */
final class AggregatorTest extends AiUsageTestCase
{
    public function test_decimal_sums_are_exact(): void
    {
        $this->seed();
        $aggregator = $this->service(AiUsageAggregator::class);

        self::assertSame('22.80', $aggregator->total(Period::all()));
        self::assertSame(
            [
                ['provider' => 'Runway', 'credits' => '12.50'],
                ['provider' => 'OpenAI', 'credits' => '6.00'],
                ['provider' => 'Midjourney', 'credits' => '3.30'],
                ['provider' => 'Kling', 'credits' => '1.00'],
            ],
            $aggregator->byProvider(Period::all()),
        );
        self::assertSame(
            [
                [
                    'designerId' => $this->dave->id,
                    'name' => 'Dave Test',
                    'total' => '12.30',
                    'byProvider' => [
                        ['provider' => 'OpenAI', 'credits' => '6.00'],
                        ['provider' => 'Runway', 'credits' => '5.00'],
                        ['provider' => 'Kling', 'credits' => '1.00'],
                        ['provider' => 'Midjourney', 'credits' => '0.30'],
                    ],
                ],
                [
                    'designerId' => $this->eve->id,
                    'name' => 'Eve Test',
                    'total' => '10.50',
                    'byProvider' => [['provider' => 'Runway', 'credits' => '7.50'], ['provider' => 'Midjourney', 'credits' => '3.00']],
                ],
            ],
            $aggregator->byDesigner(Period::all()),
        );
    }

    public function test_point_one_plus_point_two_is_point_three(): void
    {
        $task = $this->taskOfDave(TaskStatus::UnderReview);
        $round = $this->insertRound($task, 1);
        $this->usage($round, 'Kling', '0.10');
        $this->usage($round, 'Kling', '0.20');
        $aggregator = $this->service(AiUsageAggregator::class);

        self::assertSame('0.30', $aggregator->total(Period::all()));
        self::assertSame([['provider' => 'Kling', 'credits' => '0.30']], $aggregator->byProvider(Period::all()));
        self::assertSame('0.30', $aggregator->byDesigner(Period::all())[0]['total'] ?? null);
    }

    public function test_many_small_lines_add_up_without_drift(): void
    {
        $task = $this->taskOfDave(TaskStatus::UnderReview);
        $round = $this->insertRound($task, 1);
        $provider = $this->provider('Kling');
        $values = implode(',', array_fill(0, 1000, sprintf('(%d, %d, 0.10)', $round, $provider)));
        $this->database->pdo()->exec('INSERT INTO ai_usage (round_id, provider_id, credits) VALUES ' . $values);
        $big = $this->provider('Runway');
        $this->database->pdo()->exec(sprintf('INSERT INTO ai_usage (round_id, provider_id, credits) VALUES (%d, %d, 9999999.99), (%d, %d, 9999999.99)', $round, $big, $round, $big));

        $aggregator = $this->service(AiUsageAggregator::class);

        self::assertSame('20000099.98', $aggregator->total(Period::all()));
        self::assertSame(
            [['provider' => 'Runway', 'credits' => '19999999.98'], ['provider' => 'Kling', 'credits' => '100.00']],
            $aggregator->byProvider(Period::all()),
        );
    }

    public function test_equal_sums_are_ordered_by_name(): void
    {
        $task = $this->taskOfDave(TaskStatus::UnderReview);
        $round = $this->insertRound($task, 1);
        foreach (['Zeta', 'alpha', 'Mid'] as $name) {
            $this->usage($round, $name, '2.00');
        }

        self::assertSame(
            ['alpha', 'Mid', 'Zeta'],
            array_column($this->service(AiUsageAggregator::class)->byProvider(Period::all()), 'provider'),
        );
    }

    public function test_period_filters_by_round_submission_time(): void
    {
        $this->seed();
        $aggregator = $this->service(AiUsageAggregator::class);
        $week = Period::lastDays(7, $this->clock);
        $month = Period::lastDays(30, $this->clock);

        // Now is 2026-01-15 09:00: seven days back is 2026-01-08 09:00, and a round submitted at exactly that time counts.
        self::assertEquals(new DateTimeImmutable('2026-01-08 09:00:00', new DateTimeZone('UTC')), $week->from);
        self::assertSame('3.00', $aggregator->total($week));
        self::assertSame([['provider' => 'OpenAI', 'credits' => '2.00'], ['provider' => 'Kling', 'credits' => '1.00']], $aggregator->byProvider($week));
        self::assertSame(['Dave Test'], array_column($aggregator->byDesigner($week), 'name'));
        self::assertSame('3.00', $aggregator->byDesigner($week)[0]['total'] ?? null);

        self::assertSame('17.50', $aggregator->total($month));
        self::assertSame(
            [['provider' => 'Runway', 'credits' => '7.50'], ['provider' => 'OpenAI', 'credits' => '6.00'], ['provider' => 'Midjourney', 'credits' => '3.00'], ['provider' => 'Kling', 'credits' => '1.00']],
            $aggregator->byProvider($month),
        );

        // The period follows the clock.
        $this->clock->set(new DateTimeImmutable('2026-01-16 09:00:00', new DateTimeZone('UTC')));
        self::assertSame('1.00', $aggregator->total(Period::lastDays(7, $this->clock)));

        // Time of the decision or of the task does not matter, only the round's submission time.
        self::assertSame('22.80', $aggregator->total(Period::all()));
    }

    public function test_an_empty_database_gives_zero_and_nothing(): void
    {
        $aggregator = $this->service(AiUsageAggregator::class);

        foreach ([Period::all(), Period::lastDays(7, $this->clock)] as $period) {
            self::assertSame('0.00', $aggregator->total($period));
            self::assertSame([], $aggregator->byProvider($period));
            self::assertSame([], $aggregator->byDesigner($period));
        }

        // Rounds with no lines add nothing either.
        $this->insertRound($this->taskOfDave(TaskStatus::UnderReview), 1);
        self::assertSame('0.00', $aggregator->total(Period::all()));
        self::assertSame([], $aggregator->byDesigner(Period::all()));
    }

    public function test_period_all_and_last_days(): void
    {
        self::assertNull(Period::all()->from);
        self::assertEquals(new DateTimeImmutable('2025-12-16 09:00:00', new DateTimeZone('UTC')), Period::lastDays(30, $this->clock)->from);

        $this->expectException(InvalidArgumentException::class);
        Period::lastDays(0, $this->clock);
    }

    /**
     * Dave: T1 round 1 (long ago), round 2 (yesterday), T3 rounds at the edge of the 7-day window.
     * Eve: T2 round 1 (twelve days ago). Total 22.80.
     */
    private function seed(): void
    {
        $t1 = $this->insertTask($this->anna, 'One', TaskStatus::UnderReview, $this->dave);
        $t2 = $this->insertTask($this->anna, 'Two', TaskStatus::UnderReview, $this->eve);
        $t3 = $this->insertTask($this->anna, 'Three', TaskStatus::UnderReview, $this->dave);

        $r1 = $this->roundBy($this->dave, $t1, 1, '2025-12-10 12:00:00.000000');
        $this->usage($r1, 'Midjourney', '0.10');
        $this->usage($r1, 'Midjourney', '0.20');
        $this->usage($r1, 'Runway', '5.00');

        $r2 = $this->roundBy($this->dave, $t1, 2, '2026-01-14 10:00:00.000000');
        $this->usage($r2, 'Kling', '1.00');

        $r3 = $this->roundBy($this->eve, $t2, 1, '2026-01-03 10:00:00.000000');
        $this->usage($r3, 'Midjourney', '3.00');
        $this->usage($r3, 'Runway', '7.50');

        $this->usage($this->roundBy($this->dave, $t3, 1, '2026-01-08 09:00:00.000000'), 'OpenAI', '2.00');
        $this->usage($this->roundBy($this->dave, $t3, 2, '2026-01-08 08:59:59.999999'), 'OpenAI', '4.00');
    }

    private function roundBy(User $designer, int $taskId, int $number, string $submittedAt): int
    {
        $pdo = $this->database->pdo();
        $pdo->prepare('INSERT INTO rounds (task_id, number, submitted_by, submitted_at) VALUES (?, ?, ?, ?)')
            ->execute([$taskId, $number, $designer->id, $submittedAt]);

        return (int) $pdo->lastInsertId();
    }
}
