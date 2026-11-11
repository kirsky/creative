<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\History\TaskEvent;
use App\History\TaskEventType;
use App\Metrics\TimeMetricsCalculator;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The time metrics are derived from a task's events alone. The story used by most tests:
 * created Mon 06:00, picked up 09:10, submitted Tue 14:00, revisions requested Tue 18:00,
 * resubmitted Wed 10:00, approved Wed 12:00 (2026-01-12 is a Monday).
 */
final class TimeMetricsCalculatorTest extends TestCase
{
    private const int HOUR = 3600;

    private int $nextId = 1;

    private TimeMetricsCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TimeMetricsCalculator();
    }

    public function test_simple_story_numbers(): void
    {
        $metrics = $this->calculator->calculate($this->story(), self::at('2026-01-20 00:00'));

        self::assertSame(11400, $metrics->queueWaitSeconds);
        // Monday 09:10 -> Wednesday 12:00.
        self::assertSame(2 * 24 * self::HOUR + 2 * self::HOUR + 50 * 60, $metrics->cycleSeconds);
        self::assertSame(6 * self::HOUR, $metrics->reviewSeconds);
        self::assertSame(16 * self::HOUR, $metrics->revisionTurnaroundSeconds);
        self::assertSame([11400], $metrics->queueIntervals);
        self::assertSame([4 * self::HOUR, 2 * self::HOUR], $metrics->reviewDurations);
        self::assertSame([16 * self::HOUR], $metrics->revisionTurnarounds);
    }

    public function test_release_and_repickup_sums_both_new_intervals_for_queue_wait(): void
    {
        $metrics = $this->calculator->calculate([
            $this->event(TaskEventType::TaskCreated, 'Mon 06:00'),
            $this->event(TaskEventType::TaskPickedUp, 'Mon 09:10'),
            $this->event(TaskEventType::TaskReleased, 'Mon 10:00'),
            $this->event(TaskEventType::TaskPickedUp, 'Tue 08:00'),
            $this->event(TaskEventType::TaskApproved, 'Tue 12:00'),
        ], self::at('2026-02-01 00:00'));

        self::assertSame(11400 + 22 * self::HOUR, $metrics->queueWaitSeconds);
        self::assertSame([11400, 22 * self::HOUR], $metrics->queueIntervals);
        // The cycle starts at the first pickup and the re-queued time is part of it.
        self::assertSame(26 * self::HOUR + 50 * 60, $metrics->cycleSeconds);
    }

    public function test_task_released_again_and_still_queued_runs_the_open_interval_to_now(): void
    {
        $metrics = $this->calculator->calculate([
            $this->event(TaskEventType::TaskCreated, 'Mon 06:00'),
            $this->event(TaskEventType::TaskPickedUp, 'Mon 09:10'),
            $this->event(TaskEventType::TaskReleased, 'Mon 10:00'),
        ], self::at('2026-01-12 12:00'));

        self::assertSame(11400 + 2 * self::HOUR, $metrics->queueWaitSeconds);
        self::assertSame([11400], $metrics->queueIntervals);
        self::assertSame(2 * self::HOUR + 50 * 60, $metrics->cycleSeconds);
    }

    public function test_cancelled_task_cycle_ends_at_cancel(): void
    {
        $metrics = $this->calculator->calculate([
            $this->event(TaskEventType::TaskCreated, 'Mon 06:00'),
            $this->event(TaskEventType::TaskPickedUp, 'Mon 09:10'),
            $this->event(TaskEventType::TaskCancelled, 'Mon 11:10'),
        ], self::at('2026-02-01 00:00'));

        self::assertSame(2 * self::HOUR, $metrics->cycleSeconds);
        self::assertSame(11400, $metrics->queueWaitSeconds);
        self::assertSame(0, $metrics->reviewSeconds);
        self::assertSame(0, $metrics->revisionTurnaroundSeconds);
    }

    public function test_cancelled_while_new_ends_the_queue_at_the_cancel_and_has_no_cycle(): void
    {
        $metrics = $this->calculator->calculate([
            $this->event(TaskEventType::TaskCreated, 'Mon 06:00'),
            $this->event(TaskEventType::TaskCancelled, 'Mon 08:00'),
        ], self::at('2026-02-01 00:00'));

        self::assertSame(2 * self::HOUR, $metrics->queueWaitSeconds);
        self::assertNull($metrics->cycleSeconds);
        self::assertSame([], $metrics->queueIntervals);
    }

    public function test_cancelled_after_release_ends_the_second_queue_interval_at_the_cancel(): void
    {
        $metrics = $this->calculator->calculate([
            $this->event(TaskEventType::TaskCreated, 'Mon 06:00'),
            $this->event(TaskEventType::TaskPickedUp, 'Mon 07:00'),
            $this->event(TaskEventType::TaskReleased, 'Mon 08:00'),
            $this->event(TaskEventType::TaskCancelled, 'Mon 10:00'),
        ], self::at('2026-02-01 00:00'));

        self::assertSame(3 * self::HOUR, $metrics->queueWaitSeconds);
        self::assertSame([self::HOUR], $metrics->queueIntervals);
        self::assertSame(3 * self::HOUR, $metrics->cycleSeconds);
    }

    public function test_cancelled_under_review_counts_the_review_to_the_cancel_but_not_in_the_list(): void
    {
        $metrics = $this->calculator->calculate([
            $this->event(TaskEventType::TaskCreated, 'Mon 06:00'),
            $this->event(TaskEventType::TaskPickedUp, 'Mon 07:00'),
            $this->event(TaskEventType::RoundSubmitted, 'Mon 10:00'),
            $this->event(TaskEventType::RevisionsRequested, 'Mon 12:00'),
            $this->event(TaskEventType::RoundSubmitted, 'Mon 13:00'),
            $this->event(TaskEventType::TaskCancelled, 'Mon 15:00'),
        ], self::at('2026-02-01 00:00'));

        self::assertSame(2 * self::HOUR + 2 * self::HOUR, $metrics->reviewSeconds);
        self::assertSame([2 * self::HOUR], $metrics->reviewDurations);
        self::assertSame(self::HOUR, $metrics->revisionTurnaroundSeconds);
        self::assertSame([self::HOUR], $metrics->revisionTurnarounds);
        self::assertSame(8 * self::HOUR, $metrics->cycleSeconds);
    }

    public function test_cancelled_while_revisions_are_open_counts_the_turnaround_to_the_cancel_but_not_in_the_list(): void
    {
        $metrics = $this->calculator->calculate([
            $this->event(TaskEventType::TaskCreated, 'Mon 06:00'),
            $this->event(TaskEventType::TaskPickedUp, 'Mon 07:00'),
            $this->event(TaskEventType::RoundSubmitted, 'Mon 10:00'),
            $this->event(TaskEventType::RevisionsRequested, 'Mon 12:00'),
            $this->event(TaskEventType::TaskCancelled, 'Mon 15:30'),
        ], self::at('2026-02-01 00:00'));

        self::assertSame(3 * self::HOUR + 30 * 60, $metrics->revisionTurnaroundSeconds);
        self::assertSame([], $metrics->revisionTurnarounds);
        self::assertSame([2 * self::HOUR], $metrics->reviewDurations);
    }

    public function test_never_picked_up_task_has_null_cycle_and_queue_wait_running_to_now(): void
    {
        $metrics = $this->calculator->calculate([
            $this->event(TaskEventType::TaskCreated, 'Mon 06:00'),
        ], self::at('2026-01-12 07:30:15'));

        self::assertNull($metrics->cycleSeconds);
        self::assertSame(self::HOUR + 30 * 60 + 15, $metrics->queueWaitSeconds);
        self::assertSame([], $metrics->queueIntervals);
        self::assertSame(0, $metrics->reviewSeconds);
        self::assertSame(0, $metrics->revisionTurnaroundSeconds);
    }

    public function test_open_review_interval_runs_to_now_but_not_in_closed_lists(): void
    {
        $metrics = $this->calculator->calculate([
            $this->event(TaskEventType::TaskCreated, 'Mon 06:00'),
            $this->event(TaskEventType::TaskPickedUp, 'Mon 09:10'),
            $this->event(TaskEventType::RoundSubmitted, 'Tue 14:00'),
        ], self::at('2026-01-13 16:30'));

        self::assertSame(2 * self::HOUR + 30 * 60, $metrics->reviewSeconds);
        self::assertSame([], $metrics->reviewDurations);
        self::assertSame(31 * self::HOUR + 20 * 60, $metrics->cycleSeconds);
    }

    public function test_open_revision_turnaround_runs_to_now_but_not_in_the_list(): void
    {
        $metrics = $this->calculator->calculate([
            $this->event(TaskEventType::TaskCreated, 'Mon 06:00'),
            $this->event(TaskEventType::TaskPickedUp, 'Mon 09:10'),
            $this->event(TaskEventType::RoundSubmitted, 'Tue 14:00'),
            $this->event(TaskEventType::RevisionsRequested, 'Tue 18:00'),
        ], self::at('2026-01-13 20:00'));

        self::assertSame(2 * self::HOUR, $metrics->revisionTurnaroundSeconds);
        self::assertSame([], $metrics->revisionTurnarounds);
        self::assertSame(4 * self::HOUR, $metrics->reviewSeconds);
        self::assertSame([4 * self::HOUR], $metrics->reviewDurations);
    }

    public function test_a_release_between_revisions_and_resubmission_does_not_close_the_turnaround(): void
    {
        $metrics = $this->calculator->calculate([
            $this->event(TaskEventType::TaskCreated, 'Mon 06:00'),
            $this->event(TaskEventType::TaskPickedUp, 'Mon 07:00'),
            $this->event(TaskEventType::RoundSubmitted, 'Mon 08:00'),
            $this->event(TaskEventType::RevisionsRequested, 'Mon 09:00'),
            $this->event(TaskEventType::TaskReleased, 'Mon 10:00'),
            $this->event(TaskEventType::TaskPickedUp, 'Mon 12:00'),
            $this->event(TaskEventType::RoundSubmitted, 'Mon 15:00'),
        ], self::at('2026-02-01 00:00'));

        self::assertSame([6 * self::HOUR], $metrics->revisionTurnarounds);
        self::assertSame(6 * self::HOUR, $metrics->revisionTurnaroundSeconds);
        self::assertSame([self::HOUR, 2 * self::HOUR], $metrics->queueIntervals);
    }

    public function test_no_events_returns_zeros_and_null_cycle(): void
    {
        $metrics = $this->calculator->calculate([], self::at('2026-01-12 07:30'));

        self::assertSame(0, $metrics->queueWaitSeconds);
        self::assertNull($metrics->cycleSeconds);
        self::assertSame(0, $metrics->reviewSeconds);
        self::assertSame(0, $metrics->revisionTurnaroundSeconds);
        self::assertSame([], $metrics->queueIntervals);
        self::assertSame([], $metrics->reviewDurations);
        self::assertSame([], $metrics->revisionTurnarounds);
    }

    public function test_unsorted_input_is_sorted_and_the_input_is_left_alone(): void
    {
        $events = $this->story();
        $shuffled = array_reverse($events);
        $copy = $shuffled;

        $metrics = $this->calculator->calculate($shuffled, self::at('2026-02-01 00:00'));

        self::assertEquals($this->calculator->calculate($events, self::at('2026-02-01 00:00')), $metrics);
        self::assertSame(11400, $metrics->queueWaitSeconds);
        self::assertSame($copy, $shuffled);
    }

    public function test_events_at_the_same_instant_are_ordered_by_id(): void
    {
        // Submitted and decided at the very same instant: only the id says which came first.
        $events = [
            new TaskEvent(30, 1, TaskEventType::RevisionsRequested, 1, 'a', self::at('2026-01-12 10:00'), []),
            new TaskEvent(20, 1, TaskEventType::RoundSubmitted, 1, 'a', self::at('2026-01-12 10:00'), []),
            new TaskEvent(10, 1, TaskEventType::TaskPickedUp, 1, 'a', self::at('2026-01-12 08:00'), []),
            new TaskEvent(5, 1, TaskEventType::TaskCreated, 1, 'a', self::at('2026-01-12 08:00'), []),
        ];

        $metrics = $this->calculator->calculate($events, self::at('2026-01-12 12:00'));

        self::assertSame([0], $metrics->reviewDurations);
        self::assertSame(2 * self::HOUR, $metrics->revisionTurnaroundSeconds);
        self::assertSame([], $metrics->revisionTurnarounds);
        self::assertSame([0], $metrics->queueIntervals);
    }

    public function test_fractional_seconds_are_floored(): void
    {
        $metrics = $this->calculator->calculate([
            new TaskEvent(1, 1, TaskEventType::TaskCreated, 1, 'a', self::at('2026-01-12 06:00:00.900000'), []),
            new TaskEvent(2, 1, TaskEventType::TaskPickedUp, 1, 'a', self::at('2026-01-12 06:00:02.800000'), []),
            new TaskEvent(3, 1, TaskEventType::RoundSubmitted, 1, 'a', self::at('2026-01-12 06:00:03.100000'), []),
            new TaskEvent(4, 1, TaskEventType::TaskApproved, 1, 'a', self::at('2026-01-12 06:00:04.099999'), []),
        ], self::at('2026-02-01 00:00'));

        self::assertSame(1, $metrics->queueWaitSeconds); // 1.9 s
        self::assertSame(1, $metrics->cycleSeconds); // 1.299999 s
        self::assertSame(0, $metrics->reviewSeconds); // 0.999999 s
    }

    public function test_now_before_an_open_interval_never_gives_a_negative_number(): void
    {
        $metrics = $this->calculator->calculate([
            $this->event(TaskEventType::TaskCreated, 'Mon 06:00'),
            $this->event(TaskEventType::TaskPickedUp, 'Mon 09:10'),
            $this->event(TaskEventType::RoundSubmitted, 'Mon 10:00'),
        ], self::at('2026-01-12 05:00'));

        self::assertSame(11400, $metrics->queueWaitSeconds);
        self::assertSame(0, $metrics->reviewSeconds);
        self::assertSame(0, $metrics->revisionTurnaroundSeconds);
        self::assertSame(0, $metrics->cycleSeconds);
    }

    /**
     * @param list<TaskEventType> $types
     */
    #[DataProvider('eventsOutOfSequence')]
    public function test_events_out_of_sequence_are_ignored_without_an_exception(array $types, int $queue, ?int $cycle, int $review, int $revision): void
    {
        $events = [];
        foreach ($types as $i => $type) {
            $events[] = new TaskEvent($i + 1, 1, $type, 1, 'a', self::at('2026-01-12 06:00')->modify('+' . ($i + 1) . ' hours'), []);
        }

        $metrics = $this->calculator->calculate($events, self::at('2026-01-12 06:00')->modify('+' . (count($types) + 1) . ' hours'));

        self::assertSame($queue, $metrics->queueWaitSeconds, 'queue');
        self::assertSame($cycle, $metrics->cycleSeconds, 'cycle');
        self::assertSame($review, $metrics->reviewSeconds, 'review');
        self::assertSame($revision, $metrics->revisionTurnaroundSeconds, 'revision');
    }

    /**
     * @return iterable<string, array{list<TaskEventType>, int, ?int, int, int}>
     */
    public static function eventsOutOfSequence(): iterable
    {
        // Events are one hour apart; now is one hour after the last one.
        yield 'a decision without a submission' => [
            [TaskEventType::TaskCreated, TaskEventType::TaskPickedUp, TaskEventType::RevisionsRequested],
            3600, 7200, 0, 0,
        ];
        yield 'an approval without a pickup' => [
            [TaskEventType::TaskCreated, TaskEventType::TaskApproved],
            3600, null, 0, 0,
        ];
        yield 'a duplicate creation and pickup' => [
            [TaskEventType::TaskCreated, TaskEventType::TaskCreated, TaskEventType::TaskPickedUp, TaskEventType::TaskPickedUp],
            7200, 7200, 0, 0,
        ];
        yield 'a release that nobody holds' => [
            [TaskEventType::TaskCreated, TaskEventType::TaskReleased, TaskEventType::TaskPickedUp],
            7200, 3600, 0, 0,
        ];
        yield 'a duplicate submission' => [
            [TaskEventType::TaskCreated, TaskEventType::TaskPickedUp, TaskEventType::RoundSubmitted, TaskEventType::RoundSubmitted],
            3600, 10800, 7200, 0,
        ];
        yield 'events after the task is closed' => [
            [TaskEventType::TaskCreated, TaskEventType::TaskPickedUp, TaskEventType::TaskCancelled, TaskEventType::RoundSubmitted, TaskEventType::TaskPickedUp],
            3600, 3600, 0, 0,
        ];
        yield 'brief edits and AI usage changes are no part of the sequence' => [
            [TaskEventType::TaskCreated, TaskEventType::BriefEdited, TaskEventType::TaskPickedUp, TaskEventType::AiUsageChanged],
            7200, 7200, 0, 0,
        ];
    }

    public function test_metrics_are_never_negative_for_random_valid_sequences(): void
    {
        mt_srand(15);
        $base = self::at('2026-01-12 06:00');

        for ($run = 0; $run < 300; ++$run) {
            $types = $this->randomSequence();
            $at = $base;
            $events = [];
            foreach ($types as $i => $type) {
                $at = $at->modify('+' . mt_rand(0, 90000) . ' seconds')->modify('+' . mt_rand(0, 999999) . ' microseconds');
                $events[] = new TaskEvent($i + 1, 1, $type, 1, 'a', $at, []);
            }
            shuffle($events);

            $metrics = $this->calculator->calculate($events, $at->modify('+' . mt_rand(0, 200000) . ' seconds'));

            self::assertGreaterThanOrEqual(0, $metrics->queueWaitSeconds);
            self::assertGreaterThanOrEqual(0, $metrics->cycleSeconds ?? 0);
            self::assertGreaterThanOrEqual(0, $metrics->reviewSeconds);
            self::assertGreaterThanOrEqual(0, $metrics->revisionTurnaroundSeconds);
            foreach ([...$metrics->queueIntervals, ...$metrics->reviewDurations, ...$metrics->revisionTurnarounds] as $interval) {
                self::assertGreaterThanOrEqual(0, $interval);
            }
            // The closed intervals are part of the totals.
            self::assertLessThanOrEqual($metrics->queueWaitSeconds, array_sum($metrics->queueIntervals));
            self::assertLessThanOrEqual($metrics->reviewSeconds, array_sum($metrics->reviewDurations));
            self::assertLessThanOrEqual($metrics->revisionTurnaroundSeconds, array_sum($metrics->revisionTurnarounds));
            self::assertSame($metrics->cycleSeconds === null, !in_array(TaskEventType::TaskPickedUp, $types, true));
        }
    }

    /**
     * A random, valid walk through the workflow, ending open or closed.
     *
     * @return list<TaskEventType>
     */
    private function randomSequence(): array
    {
        $types = [TaskEventType::TaskCreated];
        $state = 'new';
        for ($step = mt_rand(0, 12); $step > 0 && $state !== 'closed'; --$step) {
            [$type, $state] = match ($state) {
                'new' => mt_rand(0, 9) === 0 ? [TaskEventType::TaskCancelled, 'closed'] : [TaskEventType::TaskPickedUp, 'work'],
                'work' => match (mt_rand(0, 3)) {
                    0 => [TaskEventType::TaskReleased, 'new'],
                    1 => [TaskEventType::TaskCancelled, 'closed'],
                    default => [TaskEventType::RoundSubmitted, 'review'],
                },
                'review' => match (mt_rand(0, 3)) {
                    0 => [TaskEventType::TaskApproved, 'closed'],
                    1 => [TaskEventType::TaskCancelled, 'closed'],
                    default => [TaskEventType::RevisionsRequested, 'revisions'],
                },
                default => match (mt_rand(0, 2)) {
                    0 => [TaskEventType::TaskReleased, 'new'],
                    default => [TaskEventType::RoundSubmitted, 'review'],
                },
            };
            $types[] = $type;
        }

        return $types;
    }

    /**
     * @return list<TaskEvent>
     */
    private function story(): array
    {
        return [
            $this->event(TaskEventType::TaskCreated, 'Mon 06:00'),
            $this->event(TaskEventType::TaskPickedUp, 'Mon 09:10'),
            $this->event(TaskEventType::RoundSubmitted, 'Tue 14:00'),
            $this->event(TaskEventType::RevisionsRequested, 'Tue 18:00'),
            $this->event(TaskEventType::RoundSubmitted, 'Wed 10:00'),
            $this->event(TaskEventType::TaskApproved, 'Wed 12:00'),
        ];
    }

    /**
     * @param string $when "Mon 06:00": a weekday of the week of 2026-01-12 and a time (UTC)
     */
    private function event(TaskEventType $type, string $when): TaskEvent
    {
        $at = self::at('2026-01-12 00:00')->modify('this ' . $when);

        return new TaskEvent($this->nextId++, 1, $type, 1, 'Actor', $at, []);
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }
}
