<?php

declare(strict_types=1);

namespace App\Tests\Tasks;

use App\Shared\Http\ApiError;
use App\Tasks\TaskStatus;
use App\Tasks\TaskWorkflow;
use App\Tasks\Transition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The tests carry their own copy of spec section 4.2, written out pair by pair. They must not be
 * generated from TaskWorkflow, or they would agree with it whatever it says.
 */
final class TaskWorkflowTest extends TestCase
{
    /**
     * Every allowed (from, transition) pair of section 4.2 and the status it leads to.
     *
     * @return array<string, array{TaskStatus, Transition, TaskStatus}>
     */
    public static function allowedPairs(): array
    {
        return [
            'new + Pickup' => [TaskStatus::New, Transition::Pickup, TaskStatus::InProgress],
            'in_progress + Submit' => [TaskStatus::InProgress, Transition::Submit, TaskStatus::UnderReview],
            'revisions_required + Submit' => [TaskStatus::RevisionsRequired, Transition::Submit, TaskStatus::UnderReview],
            'under_review + RequestRevisions' => [TaskStatus::UnderReview, Transition::RequestRevisions, TaskStatus::RevisionsRequired],
            'under_review + Approve' => [TaskStatus::UnderReview, Transition::Approve, TaskStatus::Completed],
            'in_progress + Release' => [TaskStatus::InProgress, Transition::Release, TaskStatus::New],
            'revisions_required + Release' => [TaskStatus::RevisionsRequired, Transition::Release, TaskStatus::New],
            'new + Cancel' => [TaskStatus::New, Transition::Cancel, TaskStatus::Cancelled],
            'in_progress + Cancel' => [TaskStatus::InProgress, Transition::Cancel, TaskStatus::Cancelled],
            'under_review + Cancel' => [TaskStatus::UnderReview, Transition::Cancel, TaskStatus::Cancelled],
            'revisions_required + Cancel' => [TaskStatus::RevisionsRequired, Transition::Cancel, TaskStatus::Cancelled],
        ];
    }

    public function test_exactly_eleven_pairs_allowed(): void
    {
        $workflow = new TaskWorkflow();
        $allowed = [];
        $rejected = [];

        foreach (TaskStatus::cases() as $from) {
            foreach (Transition::cases() as $transition) {
                $pair = $from->value . ' + ' . $transition->name;
                try {
                    $workflow->next($from, $transition);
                    $allowed[] = $pair;
                } catch (ApiError $error) {
                    self::assertSame(409, $error->status, $pair);
                    self::assertSame('invalid_transition', $error->code, $pair);
                    $rejected[] = $pair;
                }
            }
        }

        self::assertCount(6, TaskStatus::cases());
        self::assertCount(6, Transition::cases());
        self::assertCount(11, $allowed);
        self::assertCount(25, $rejected);

        $expected = array_keys(self::allowedPairs());
        sort($expected);
        sort($allowed);
        self::assertSame($expected, $allowed, 'the allowed pairs are exactly the ones in section 4.2');
    }

    #[DataProvider('allowedPairs')]
    public function test_each_allowed_pair_target(TaskStatus $from, Transition $transition, TaskStatus $target): void
    {
        self::assertSame($target, new TaskWorkflow()->next($from, $transition));
        self::assertTrue(new TaskWorkflow()->allows($from, $transition));
    }

    /**
     * @return iterable<string, array{TaskStatus, Transition}>
     */
    public static function rejectedPairs(): iterable
    {
        $allowed = [];
        foreach (self::allowedPairs() as [$from, $transition]) {
            $allowed[$from->value . ' + ' . $transition->name] = true;
        }

        foreach (TaskStatus::cases() as $from) {
            foreach (Transition::cases() as $transition) {
                $pair = $from->value . ' + ' . $transition->name;
                if (!isset($allowed[$pair])) {
                    yield $pair => [$from, $transition];
                }
            }
        }
    }

    #[DataProvider('rejectedPairs')]
    public function test_each_other_pair_is_rejected_with_409_invalid_transition(TaskStatus $from, Transition $transition): void
    {
        self::assertFalse(new TaskWorkflow()->allows($from, $transition));

        try {
            new TaskWorkflow()->next($from, $transition);
            self::fail('expected ApiError');
        } catch (ApiError $error) {
            self::assertSame(409, $error->status);
            self::assertSame('invalid_transition', $error->code);
        }
    }

    public function test_completed_and_cancelled_are_terminal(): void
    {
        $workflow = new TaskWorkflow();

        foreach ([TaskStatus::Completed, TaskStatus::Cancelled] as $terminal) {
            self::assertTrue($terminal->isTerminal());
            foreach (Transition::cases() as $transition) {
                self::assertFalse($workflow->allows($terminal, $transition), $terminal->value . ' + ' . $transition->name);
            }
        }

        foreach ([TaskStatus::New, TaskStatus::InProgress, TaskStatus::UnderReview, TaskStatus::RevisionsRequired] as $open) {
            self::assertFalse($open->isTerminal(), $open->value);
        }
    }

    public function test_status_values_are_the_api_codes(): void
    {
        self::assertSame(
            ['new', 'in_progress', 'under_review', 'revisions_required', 'completed', 'cancelled'],
            array_map(static fn(TaskStatus $status): string => $status->value, TaskStatus::cases()),
        );
    }

    public function test_a_full_lifecycle_with_one_revision_round(): void
    {
        $workflow = new TaskWorkflow();

        $status = TaskStatus::New;
        foreach ([
            Transition::Pickup,
            Transition::Submit,
            Transition::RequestRevisions,
            Transition::Submit,
            Transition::Approve,
        ] as $transition) {
            $status = $workflow->next($status, $transition);
        }

        self::assertSame(TaskStatus::Completed, $status);
    }
}
