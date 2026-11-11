<?php

declare(strict_types=1);

namespace App\Tests\Analytics;

use App\Identity\Role;
use App\Identity\User;
use App\Tasks\TaskStatus;
use App\Tests\AiUsage\AiUsageTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /api/analytics/dashboard: the manager's numbers. Fixtures are built through the real endpoints
 * with a clock the test moves; direct inserts are for bulk and odd cases only.
 */
final class DashboardTest extends AiUsageTestCase
{
    public function test_totals_by_status_include_cancelled_but_active_excludes_it(): void
    {
        $this->at('2026-01-14 09:00:00');
        $this->createTask(); // new
        $inProgress = $this->createTask();
        $this->pickUp($inProgress);
        $underReview = $this->createTask();
        $this->pickUp($underReview);
        $this->submitTask($underReview);
        $revisions = $this->createTask();
        $this->pickUp($revisions);
        $this->submitTask($revisions);
        self::assertSame(200, $this->requestRevisions($revisions)->getStatusCode());
        $completed = $this->createTask();
        $this->pickUp($completed);
        $this->submitTask($completed);
        self::assertSame(200, $this->approve($completed)->getStatusCode());
        $cancelled = $this->createTask();
        self::assertSame(200, $this->cancel($cancelled)->getStatusCode());

        $totals = self::jsonArray($this->dashboard(), 'totals');

        self::assertSame([
            'total' => 6,
            'active' => 3,
            'cancelled' => 1,
            'byStatus' => [
                'new' => 1,
                'in_progress' => 1,
                'under_review' => 1,
                'revisions_required' => 1,
                'completed' => 1,
                'cancelled' => 1,
            ],
        ], $totals);
    }

    public function test_empty_database_returns_zeros_and_null_averages(): void
    {
        $response = $this->dashboard();

        self::assertSame([
            'totals' => [
                'total' => 0,
                'active' => 0,
                'cancelled' => 0,
                'byStatus' => ['new' => 0, 'in_progress' => 0, 'under_review' => 0, 'revisions_required' => 0, 'completed' => 0, 'cancelled' => 0],
            ],
            'overdue' => ['count' => 0, 'tasks' => []],
            'averages' => [
                'cycleSeconds' => null,
                'queueWaitSeconds' => null,
                'reviewSecondsPerRound' => null,
                'revisionTurnaroundSeconds' => null,
                'completedCount' => 0,
            ],
            'workload' => [
                ['designerId' => $this->dave->id, 'name' => 'Dave Test', 'inProgress' => 0, 'revisionsRequired' => 0, 'underReview' => 0, 'overdue' => 0],
                ['designerId' => $this->eve->id, 'name' => 'Eve Test', 'inProgress' => 0, 'revisionsRequired' => 0, 'underReview' => 0, 'overdue' => 0],
            ],
            'ai' => ['total' => '0.00', 'byProvider' => [], 'byDesigner' => []],
        ], self::decodeJson($response));
        // Lists are lists on the wire, not objects.
        self::assertStringContainsString('"tasks":[]', (string) $response->getBody());
        self::assertStringContainsString('"byProvider":[]', (string) $response->getBody());
    }

    public function test_no_completed_tasks_gives_null_cycle_average(): void
    {
        $this->at('2026-01-14 09:00:00');
        $id = $this->createTask();
        $this->at('2026-01-14 09:00:30');
        $this->pickUp($id);
        $this->at('2026-01-14 09:10:00');
        $this->submitTask($id);
        $this->at('2026-01-14 09:15:00');
        self::assertSame(200, $this->requestRevisions($id)->getStatusCode());

        $averages = self::jsonArray($this->dashboard(), 'averages');

        self::assertSame([
            'cycleSeconds' => null,
            'queueWaitSeconds' => 30,
            'reviewSecondsPerRound' => 300,
            'revisionTurnaroundSeconds' => null,
            'completedCount' => 0,
        ], $averages);
    }

    public function test_averages_are_the_floor_of_the_mean_on_a_hand_computed_fixture(): void
    {
        // Task 1: queue 10 s; reviews 120 s and 20 s; turnaround 30 s; cycle 06:00:10 to 06:04:00 = 230 s.
        $this->at('2026-01-12 06:00:00');
        $one = $this->createTask();
        $this->at('2026-01-12 06:00:10');
        $this->pickUp($one);
        $this->at('2026-01-12 06:01:10');
        $this->submitTask($one);
        $this->at('2026-01-12 06:03:10');
        self::assertSame(200, $this->requestRevisions($one)->getStatusCode());
        $this->at('2026-01-12 06:03:40');
        $this->submitTask($one, 'v2.png');
        $this->at('2026-01-12 06:04:00');
        self::assertSame(200, $this->approve($one)->getStatusCode());

        // Task 2: queue 21 s; review 62 s; cycle 07:00:21 to 07:02:02 = 101 s.
        $this->at('2026-01-12 07:00:00');
        $two = $this->createTask();
        $this->at('2026-01-12 07:00:21');
        $this->pickUp($two);
        $this->at('2026-01-12 07:01:00');
        $this->submitTask($two);
        $this->at('2026-01-12 07:02:02');
        self::assertSame(200, $this->approve($two)->getStatusCode());

        // Task 3 is still in progress: its queue wait of 4 s counts, its (open) cycle does not.
        $this->at('2026-01-12 08:00:00');
        $three = $this->createTask();
        $this->at('2026-01-12 08:00:04');
        $this->pickUp($three);

        // Task 4 was never picked up: it has no closed interval at all.
        $this->at('2026-01-12 09:00:00');
        $this->createTask();

        $this->at('2026-01-15 09:00:00');
        $averages = self::jsonArray($this->dashboard(), 'averages');

        self::assertSame([
            'cycleSeconds' => 165, // (230 + 101) / 2 = 165.5
            'queueWaitSeconds' => 11, // (10 + 21 + 4) / 3 = 11.67
            'reviewSecondsPerRound' => 67, // (120 + 20 + 62) / 3 = 67.33
            'revisionTurnaroundSeconds' => 30,
            'completedCount' => 2,
        ], $averages);
    }

    public function test_overdue_excludes_completed_and_cancelled_and_lists_tasks(): void
    {
        // The clock is 2026-01-15 09:00:00.
        $late = $this->insertTask($this->anna, 'Late', deadline: '2026-01-15 08:59:59');
        $earliest = $this->insertTask($this->anna, 'Earliest', TaskStatus::InProgress, $this->dave, deadline: '2026-01-10 10:00:00');
        $review = $this->insertTask($this->anna, 'Review', TaskStatus::UnderReview, $this->dave, deadline: '2026-01-12 10:00:00');
        $this->insertTask($this->anna, 'Done', TaskStatus::Completed, $this->dave, deadline: '2026-01-05 10:00:00');
        $this->insertTask($this->anna, 'Gone', TaskStatus::Cancelled, deadline: '2026-01-05 10:00:00');
        $this->insertTask($this->anna, 'Exactly now', deadline: '2026-01-15 09:00:00');
        $this->insertTask($this->anna, 'Future', deadline: '2026-01-15 09:00:01');

        $response = $this->dashboard();

        self::assertSame(3, self::jsonAt($response, 'overdue.count'));
        $tasks = self::jsonArray($response, 'overdue.tasks');
        self::assertSame([$earliest, $review, $late], array_map(static fn(mixed $task): mixed => is_array($task) ? $task['id'] : null, $tasks));
        $first = $tasks[0];
        self::assertIsArray($first);
        self::assertSame('Earliest', $first['offer']);
        self::assertTrue($first['isOverdue']);
        self::assertSame('in_progress', $first['status']);
        self::assertSame(['id' => $this->dave->id, 'displayName' => 'Dave Test'], $first['assignee']);
        self::assertSame([], $first['allowedActions'], 'A manager is read-only.');
    }

    public function test_overdue_fractional_now_counts_a_deadline_in_the_same_second_as_overdue(): void
    {
        $this->clock->set(new DateTimeImmutable('2026-01-15 09:00:00.500000', new DateTimeZone('UTC')));
        $this->insertTask($this->anna, 'Same second', deadline: '2026-01-15 09:00:00');
        $this->insertTask($this->anna, 'Next second', deadline: '2026-01-15 09:00:01');

        self::assertSame(1, self::jsonAt($this->dashboard(), 'overdue.count'));
    }

    public function test_overdue_count_is_the_full_count_while_the_list_is_capped_at_fifty(): void
    {
        $ids = [];
        for ($i = 0; $i < 51; ++$i) {
            // Inserted in reverse, so that id order is not deadline order.
            $ids[] = $this->insertTask($this->anna, 'Late ' . $i, deadline: (new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')))->modify(sprintf('-%d minutes', $i))->format('Y-m-d H:i:s'));
        }

        $response = $this->dashboard();

        self::assertSame(51, self::jsonAt($response, 'overdue.count'));
        $listed = array_map(static fn(mixed $task): mixed => is_array($task) ? $task['id'] : null, self::jsonArray($response, 'overdue.tasks'));
        self::assertCount(50, $listed);
        // The earliest deadlines come first: the last inserted is the earliest; the first inserted is cut off.
        self::assertSame(array_slice(array_reverse($ids), 0, 50), $listed);
    }

    public function test_workload_counts_per_designer(): void
    {
        $zed = $this->createUser('zed', Role::Designer);
        // Dave: two in progress (one overdue), one under review, one in revisions (overdue); done and cancelled do not count.
        $this->insertTask($this->anna, 'A', TaskStatus::InProgress, $this->dave, deadline: '2026-01-10 10:00:00');
        $this->insertTask($this->anna, 'B', TaskStatus::InProgress, $this->dave, deadline: '2026-02-10 10:00:00');
        $this->insertTask($this->anna, 'C', TaskStatus::UnderReview, $this->dave, deadline: '2026-02-10 10:00:00');
        $this->insertTask($this->anna, 'D', TaskStatus::RevisionsRequired, $this->dave, deadline: '2026-01-11 10:00:00');
        $this->insertTask($this->anna, 'E', TaskStatus::Completed, $this->dave, deadline: '2026-01-01 10:00:00');
        $this->insertTask($this->anna, 'F', TaskStatus::Cancelled, $this->dave, deadline: '2026-01-01 10:00:00');
        // Eve: one under review.
        $this->insertTask($this->anna, 'G', TaskStatus::UnderReview, $this->eve, deadline: '2026-02-10 10:00:00');
        // Nobody's: a new task, and a buyer and a manager are not in the list.
        $this->insertTask($this->anna, 'H', deadline: '2026-01-01 10:00:00');

        $workload = self::jsonArray($this->dashboard(), 'workload');

        self::assertSame([
            ['designerId' => $this->dave->id, 'name' => 'Dave Test', 'inProgress' => 2, 'revisionsRequired' => 1, 'underReview' => 1, 'overdue' => 2],
            ['designerId' => $this->eve->id, 'name' => 'Eve Test', 'inProgress' => 0, 'revisionsRequired' => 0, 'underReview' => 1, 'overdue' => 0],
            ['designerId' => $zed->id, 'name' => 'Zed Test', 'inProgress' => 0, 'revisionsRequired' => 0, 'underReview' => 0, 'overdue' => 0],
        ], $workload);
    }

    public function test_ai_by_provider_by_designer_and_total_agree(): void
    {
        $this->at('2026-01-14 09:00:00');
        $one = $this->createTask();
        $this->pickUp($one);
        self::assertSame(201, $this->submitWithUsage($one, [['provider' => 'Runway', 'credits' => '5.10'], ['provider' => 'Kling', 'credits' => '0.20']])->getStatusCode());
        $two = $this->createTask();
        $this->pickUp($two, $this->eve);
        self::assertSame(201, $this->submitWithUsage($two, [['provider' => 'Runway', 'credits' => '7.50'], ['provider' => 'Midjourney', 'credits' => '0.10']], $this->eve)->getStatusCode());

        $ai = self::jsonArray($this->dashboard(), 'ai');

        self::assertSame([
            'total' => '12.90',
            'byProvider' => [
                ['provider' => 'Runway', 'credits' => '12.60'],
                ['provider' => 'Kling', 'credits' => '0.20'],
                ['provider' => 'Midjourney', 'credits' => '0.10'],
            ],
            'byDesigner' => [
                [
                    'designerId' => $this->eve->id,
                    'name' => 'Eve Test',
                    'total' => '7.60',
                    'byProvider' => [['provider' => 'Runway', 'credits' => '7.50'], ['provider' => 'Midjourney', 'credits' => '0.10']],
                ],
                [
                    'designerId' => $this->dave->id,
                    'name' => 'Dave Test',
                    'total' => '5.30',
                    'byProvider' => [['provider' => 'Runway', 'credits' => '5.10'], ['provider' => 'Kling', 'credits' => '0.20']],
                ],
            ],
        ], $ai);
    }

    public function test_period_7d_filters_tasks_created_and_rounds_submitted_while_workload_and_overdue_ignore_it(): void
    {
        // Now is Thursday 2026-01-15 09:00.
        // Old: created long ago, still in progress and overdue; its round was submitted 3 days ago.
        $this->at('2025-11-01 09:00:00');
        $old = $this->createTask('2025-12-01T10:00:00Z');
        $this->pickUp($old);
        $this->at('2026-01-12 09:00:00');
        self::assertSame(201, $this->submitWithUsage($old, [['provider' => 'Kling', 'credits' => '2.00']])->getStatusCode());
        // Edge: created exactly seven days before now, picked up and submitted a day ago.
        $this->at('2026-01-08 09:00:00');
        $edge = $this->createTask();
        $this->pickUp($edge);
        $this->at('2026-01-14 09:00:00');
        self::assertSame(201, $this->submitWithUsage($edge, [['provider' => 'Runway', 'credits' => '3.00']])->getStatusCode());
        // Twenty days old: in 30d, not in 7d; submitted then, so its credits are out of 7d too.
        $this->at('2025-12-26 09:00:00');
        $mid = $this->createTask();
        $this->pickUp($mid, $this->eve);
        $this->at('2025-12-27 09:00:00');
        self::assertSame(201, $this->submitWithUsage($mid, [['provider' => 'Kling', 'credits' => '4.00']], $this->eve)->getStatusCode());
        // Created two days ago and left New.
        $this->at('2026-01-13 09:00:00');
        $this->createTask();
        // Created 60 days ago and cancelled: only "all" counts it.
        $this->at('2025-11-16 09:00:00');
        $gone = $this->createTask('2025-12-01T10:00:00Z');
        self::assertSame(200, $this->cancel($gone)->getStatusCode());

        $this->at('2026-01-15 09:00:00');
        $all = $this->dashboard();
        $week = $this->dashboard('7d');
        $month = $this->dashboard('30d');

        self::assertSame(5, self::jsonAt($all, 'totals.total'));
        self::assertSame(1, self::jsonAt($all, 'totals.cancelled'));
        self::assertSame('9.00', self::jsonAt($all, 'ai.total'));

        // 7d: edge and the new one (created within seven days, the edge at exactly the start).
        self::assertSame(2, self::jsonAt($week, 'totals.total'));
        self::assertSame(0, self::jsonAt($week, 'totals.cancelled'));
        self::assertSame(1, self::jsonAt($week, 'totals.byStatus.new'));
        self::assertSame(1, self::jsonAt($week, 'totals.byStatus.under_review'));
        // Credits follow the submission time: the old task's round (3 days ago) counts, the one of 19 days ago does not.
        self::assertSame('5.00', self::jsonAt($week, 'ai.total'));
        self::assertSame([['provider' => 'Runway', 'credits' => '3.00'], ['provider' => 'Kling', 'credits' => '2.00']], self::jsonAt($week, 'ai.byProvider'));

        // 30d: all but the 60-day-old cancelled task, and not the old task created in November.
        self::assertSame(3, self::jsonAt($month, 'totals.total'));
        self::assertSame('9.00', self::jsonAt($month, 'ai.total'));

        // Workload and overdue are the same whatever the period.
        foreach (['workload', 'overdue'] as $key) {
            self::assertSame(self::jsonAt($all, $key), self::jsonAt($week, $key), $key);
            self::assertSame(self::jsonAt($all, $key), self::jsonAt($month, $key), $key);
        }
        self::assertSame(1, self::jsonAt($week, 'overdue.count'));
        self::assertSame($old, self::jsonAt($week, 'overdue.tasks.0.id'));
        self::assertSame(2, self::jsonAt($week, 'workload.0.underReview'));
        self::assertSame(1, self::jsonAt($week, 'workload.1.underReview'));
    }

    public function test_period_defaults_to_all_and_rejects_other_values(): void
    {
        $this->at('2025-01-01 09:00:00');
        $this->createTask();
        $this->at('2026-01-15 09:00:00');

        $default = $this->request('GET', '/api/analytics/dashboard', as: $this->mia);
        self::assertSame(200, $default->getStatusCode());
        self::assertSame(1, self::jsonAt($default, 'totals.total'));
        self::assertSame(0, self::jsonAt($this->dashboard('7d'), 'totals.total'));

        foreach (['1d', '', '7D', 'week', '30', 'all,7d', '[]'] as $bad) {
            $query = $bad === '[]' ? 'period[]=7d' : 'period=' . rawurlencode($bad);
            $response = $this->request('GET', '/api/analytics/dashboard?' . $query, as: $this->mia);
            self::assertSame(422, $response->getStatusCode(), $bad);
            $this->assertMatchesSpec($response);
            self::assertSame([['field' => 'period', 'code' => 'invalid_choice', 'params' => []]], self::jsonAt($response, 'error.fields'), $bad);
        }
    }

    public function test_buyer_and_designer_get_403(): void
    {
        foreach ([$this->anna, $this->dave] as $user) {
            $response = $this->request('GET', '/api/analytics/dashboard', as: $user);
            self::assertSame(403, $response->getStatusCode(), $user->username);
            $this->assertMatchesSpec($response);
            self::assertSame('forbidden', self::jsonAt($response, 'error.code'));
        }

        // Role is checked before the query is looked at.
        self::assertSame(403, $this->request('GET', '/api/analytics/dashboard?period=nonsense', as: $this->anna)->getStatusCode());
        self::assertSame(200, $this->dashboard()->getStatusCode());
    }

    public function test_unauthenticated_gets_401(): void
    {
        $response = $this->request('GET', '/api/analytics/dashboard');

        self::assertSame(401, $response->getStatusCode());
        $this->assertMatchesSpec($response);
    }

    private function dashboard(?string $period = null): ResponseInterface
    {
        $response = $this->request('GET', '/api/analytics/dashboard' . ($period === null ? '' : '?period=' . $period), as: $this->mia);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);

        return $response;
    }

    private function createTask(string $deadline = '2026-03-01T10:00:00Z'): int
    {
        $response = $this->request('POST', '/api/tasks', [
            'offer' => 'Лото Клуб',
            'geo' => ['US'],
            'creativeType' => 'video',
            'quantity' => 1,
            'description' => 'Make it pop',
            'priority' => 'normal',
            'deadline' => $deadline,
        ], $this->anna);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $id = self::jsonAt($response, 'id');

        return is_int($id) ? $id : self::fail('The new task has no id.');
    }

    private function pickUp(int $taskId, ?User $designer = null): void
    {
        $response = $this->request('POST', '/api/tasks/' . $taskId . '/pickup', as: $designer ?? $this->dave);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    private function submitTask(int $taskId, string $file = 'banner.png'): void
    {
        $response = $this->submit($taskId, [$this->uploadFor($this->dave, $file)]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    private function at(string $utc): void
    {
        $this->clock->set(new DateTimeImmutable($utc, new DateTimeZone('UTC')));
    }
}
