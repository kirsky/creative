<?php

declare(strict_types=1);

namespace App\Metrics;

use App\History\TaskEvent;
use App\History\TaskEventType;
use DateTimeImmutable;

/**
 * Derives the time metrics of one task from its events (spec section 5.4). Pure: no database, no
 * clock; "now" is passed in. All values are elapsed wall-clock seconds, the floor of the real
 * difference and never negative.
 *
 * - Queue wait: time in New. Creation to the first pickup, and each release to the next pickup.
 * - Cycle: first pickup to approval (to the cancellation, or to now while open); null if never picked
 *   up. A release does not reset the start: re-queued time is part of the cycle.
 * - Review: each round's submission to the decision that follows it; the total is the sum.
 * - Revision turnaround: each revisions request to the next submission (a release in between does not
 *   close it); the total is the sum.
 *
 * An interval still open runs to $now. Cancelling cuts every open interval at the cancel time. Such
 * intervals count in the totals but not in the lists of closed intervals.
 *
 * Events that do not fit the sequence (a decision without a submission, a duplicate pickup, an
 * approval without a pickup, anything after the task was approved or cancelled) are ignored for the
 * metric they would affect; nothing throws.
 */
final class TimeMetricsCalculator
{
    private const int MICROSECONDS = 1_000_000;

    /**
     * @param list<TaskEvent> $events in any order; sorted here by (at, id), on a copy
     */
    public function calculate(array $events, DateTimeImmutable $now): TaskTimeMetrics
    {
        usort($events, static fn(TaskEvent $a, TaskEvent $b): int => [$a->at, $a->id] <=> [$b->at, $b->id]);

        $queueWait = $review = $revision = 0;
        $queueIntervals = $reviewDurations = $revisionTurnarounds = [];
        $created = false;
        $held = false; // a designer holds the task
        $queueSince = $cycleSince = $reviewSince = $revisionSince = null;
        $cycleUntil = null;
        $end = $now;
        $closed = false;

        foreach ($events as $event) {
            $at = $event->at;

            switch ($event->type) {
                case TaskEventType::TaskCreated:
                    if (!$created && !$held) {
                        $created = true;
                        $queueSince ??= $at;
                    }
                    break;

                case TaskEventType::TaskPickedUp:
                    if ($held) {
                        break;
                    }
                    $held = true;
                    $cycleSince ??= $at;
                    if ($queueSince !== null) {
                        $interval = self::seconds($queueSince, $at);
                        $queueWait += $interval;
                        $queueIntervals[] = $interval;
                        $queueSince = null;
                    }
                    break;

                case TaskEventType::TaskReleased:
                    if ($held) {
                        $held = false;
                        $queueSince = $at;
                    }
                    break;

                case TaskEventType::RoundSubmitted:
                    if ($reviewSince === null) {
                        $reviewSince = $at;
                    }
                    if ($revisionSince !== null) {
                        $interval = self::seconds($revisionSince, $at);
                        $revision += $interval;
                        $revisionTurnarounds[] = $interval;
                        $revisionSince = null;
                    }
                    break;

                case TaskEventType::RevisionsRequested:
                    if ($reviewSince !== null) {
                        $interval = self::seconds($reviewSince, $at);
                        $review += $interval;
                        $reviewDurations[] = $interval;
                        $reviewSince = null;
                        $revisionSince = $at;
                    }
                    break;

                case TaskEventType::TaskApproved:
                    if ($reviewSince !== null) {
                        $interval = self::seconds($reviewSince, $at);
                        $review += $interval;
                        $reviewDurations[] = $interval;
                        $reviewSince = null;
                    }
                    $closed = true;
                    break;

                case TaskEventType::TaskCancelled:
                    $closed = true;
                    break;

                case TaskEventType::BriefEdited:
                case TaskEventType::AiUsageChanged:
                    break;
            }

            if ($closed) {
                // Approved or cancelled: whatever is still open ends here. Nothing later counts.
                $end = $at;
                $cycleUntil = $at;
                break;
            }
        }

        // What is still open runs to the end of the task, or to now.
        if ($queueSince !== null) {
            $queueWait += self::seconds($queueSince, $end);
        }
        if ($reviewSince !== null) {
            $review += self::seconds($reviewSince, $end);
        }
        if ($revisionSince !== null) {
            $revision += self::seconds($revisionSince, $end);
        }

        return new TaskTimeMetrics(
            $queueWait,
            $cycleSince === null ? null : self::seconds($cycleSince, $cycleUntil ?? $now),
            $review,
            $revision,
            $queueIntervals,
            $reviewDurations,
            $revisionTurnarounds,
        );
    }

    /**
     * Whole seconds from $from to $to, rounded down; 0 when $to is not after $from.
     */
    private static function seconds(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $difference = self::microseconds($to) - self::microseconds($from);

        return $difference <= 0 ? 0 : intdiv($difference, self::MICROSECONDS);
    }

    private static function microseconds(DateTimeImmutable $time): int
    {
        return $time->getTimestamp() * self::MICROSECONDS + (int) $time->format('u');
    }
}
