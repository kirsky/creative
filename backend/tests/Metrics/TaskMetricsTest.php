<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\Identity\User;
use App\Tests\Rounds\RoundsTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * GET /api/tasks/{id}: the time metrics of the task, derived from its real events as a task is driven
 * through the API with a clock the test moves.
 */
final class TaskMetricsTest extends RoundsTestCase
{
    public function test_detail_metrics_follow_a_task_through_its_whole_life(): void
    {
        // Monday 06:00: Anna creates the task.
        $this->at('2026-01-12 06:00:00');
        $created = $this->request('POST', '/api/tasks', [
            'offer' => 'Лото Клуб',
            'geo' => ['US'],
            'creativeType' => 'video',
            'quantity' => 1,
            'description' => 'Make it pop',
            'priority' => 'normal',
            'deadline' => '2026-01-30T10:00:00Z',
        ], $this->anna);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $id = self::jsonAt($created, 'id');
        self::assertIsInt($id);
        self::assertSame(['queueWaitSeconds' => 0, 'cycleSeconds' => null, 'reviewSeconds' => 0, 'revisionTurnaroundSeconds' => 0], self::jsonAt($created, 'metrics'));

        // 08:00: still waiting; the open queue interval runs to now, for everyone who can see it.
        $this->at('2026-01-12 08:00:00');
        foreach ([$this->anna, $this->dave, $this->mia] as $viewer) {
            self::assertSame(
                ['queueWaitSeconds' => 7200, 'cycleSeconds' => null, 'reviewSeconds' => 0, 'revisionTurnaroundSeconds' => 0],
                $this->metrics($id, $viewer),
                $viewer->username,
            );
        }

        $this->at('2026-01-12 09:10:00');
        self::assertSame(200, $this->request('POST', '/api/tasks/' . $id . '/pickup', as: $this->dave)->getStatusCode());
        self::assertSame(
            ['queueWaitSeconds' => 11400, 'cycleSeconds' => 0, 'reviewSeconds' => 0, 'revisionTurnaroundSeconds' => 0],
            $this->metrics($id),
        );

        $this->at('2026-01-13 14:00:00');
        self::assertSame(201, $this->submit($id, [$this->uploadFor($this->dave)])->getStatusCode());
        // Under review since 14:00; at 15:30 the open review runs to now.
        $this->at('2026-01-13 15:30:00');
        self::assertSame(5400, $this->metrics($id)['reviewSeconds']);

        $this->at('2026-01-13 18:00:00');
        self::assertSame(200, $this->requestRevisions($id)->getStatusCode());

        $this->at('2026-01-14 10:00:00');
        self::assertSame(201, $this->submit($id, [$this->uploadFor($this->dave, 'v2.png')])->getStatusCode());

        $this->at('2026-01-14 12:00:00');
        $approved = $this->approve($id);
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        $this->assertMatchesSpec($approved);

        $expected = [
            'queueWaitSeconds' => 11400,
            'cycleSeconds' => 2 * 86400 + 2 * 3600 + 50 * 60,
            'reviewSeconds' => 4 * 3600 + 2 * 3600,
            'revisionTurnaroundSeconds' => 16 * 3600,
        ];
        self::assertSame($expected, self::jsonAt($approved, 'metrics'));

        // A completed task's numbers stand still, however late it is read.
        $this->at('2026-03-01 00:00:00');
        $detail = $this->request('GET', '/api/tasks/' . $id, as: $this->anna);
        self::assertSame(200, $detail->getStatusCode());
        $this->assertMatchesSpec($detail);
        self::assertSame($expected, self::jsonAt($detail, 'metrics'));
    }

    public function test_a_task_without_events_has_zero_metrics_and_no_cycle(): void
    {
        $id = $this->insertTask($this->anna);

        $detail = $this->request('GET', '/api/tasks/' . $id, as: $this->anna);

        $this->assertMatchesSpec($detail);
        self::assertSame(['queueWaitSeconds' => 0, 'cycleSeconds' => null, 'reviewSeconds' => 0, 'revisionTurnaroundSeconds' => 0], self::jsonAt($detail, 'metrics'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function metrics(int $id, ?User $viewer = null): array
    {
        $response = $this->request('GET', '/api/tasks/' . $id, as: $viewer ?? $this->anna);
        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesSpec($response);

        return self::jsonArray($response, 'metrics');
    }

    private function at(string $utc): void
    {
        $this->clock->set(new DateTimeImmutable($utc, new DateTimeZone('UTC')));
    }
}
