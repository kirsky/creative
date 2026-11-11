<?php

declare(strict_types=1);

namespace App\Tests\Tasks;

use App\Identity\Role;
use App\Identity\User;
use App\Tasks\TaskStatus;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /api/tasks/{id}/release: the assigned designer gives a task back to the pool. The assignment
 * is closed, never deleted, so the task keeps its history.
 */
final class ReleaseTest extends ApiTestCase
{
    private User $anna;

    private User $dave;

    private User $eve;

    private User $mia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna = $this->createUser('anna', Role::Buyer);
        $this->dave = $this->createUser('dave', Role::Designer);
        $this->eve = $this->createUser('eve', Role::Designer);
        $this->mia = $this->createUser('mia', Role::Manager);
    }

    public function test_release_returns_task_to_new_and_keeps_rounds_and_history(): void
    {
        $id = $this->insertTask($this->anna);
        $this->clock->set(new DateTimeImmutable('2026-01-15 10:00:00', new DateTimeZone('UTC')));
        self::assertSame(200, $this->pickUp($id, $this->dave)->getStatusCode());
        $this->clock->set(new DateTimeImmutable('2026-01-15 11:15:30.500000', new DateTimeZone('UTC')));

        $response = $this->release($id, $this->dave);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame('new', self::jsonAt($response, 'status'));
        self::assertNull(self::jsonAt($response, 'assignee'));
        // Back in the pool, the designer who released it may pick it up again, not release it again.
        self::assertSame(['pick_up'], self::jsonAt($response, 'allowedActions'));
        self::assertSame(
            [[
                'designer' => ['id' => $this->dave->id, 'displayName' => 'Dave Test'],
                'assignedAt' => '2026-01-15T10:00:00.000Z',
                'endedAt' => '2026-01-15T11:15:30.500Z',
                'endedHow' => 'released',
            ]],
            self::jsonAt($response, 'assignments'),
        );

        self::assertSame(
            [['status' => 'new', 'assignee_id' => null, 'updated_at' => '2026-01-15 11:15:30']],
            $this->fetchRows('SELECT status, assignee_id, updated_at FROM tasks WHERE id = ?', [$id]),
        );
        $assignments = $this->fetchRows('SELECT id, designer_id, assigned_at, ended_at, ended_how FROM assignments');
        self::assertCount(1, $assignments);
        self::assertSame('2026-01-15 11:15:30.500000', $assignments[0]['ended_at']);
        self::assertSame('released', $assignments[0]['ended_how']);

        $events = $this->fetchRows('SELECT type, actor_id, payload FROM task_events ORDER BY id');
        self::assertSame(['task_picked_up', 'task_released'], array_column($events, 'type'));
        self::assertSame($this->dave->id, $events[1]['actor_id']);
        self::assertSame(['assignmentId' => $assignments[0]['id']], json_decode(is_string($events[1]['payload']) ? $events[1]['payload'] : '', true));
    }

    public function test_release_deletes_nothing_that_hangs_on_the_task(): void
    {
        $id = $this->insertTask($this->anna, status: TaskStatus::RevisionsRequired, assignee: $this->dave);
        $this->database->pdo()->prepare("INSERT INTO task_references (task_id, kind, url, file_id) VALUES (?, 'url', 'https://example.com', NULL)")->execute([$id]);
        $this->openAssignment($id, $this->dave);

        self::assertSame(200, $this->release($id, $this->dave)->getStatusCode());

        self::assertSame(1, $this->countRows('task_references'));
        self::assertSame(1, $this->countRows('task_geo'));
        self::assertSame(1, $this->countRows('assignments'));
    }

    public function test_release_is_also_allowed_when_revisions_are_required(): void
    {
        $id = $this->insertTask($this->anna, status: TaskStatus::RevisionsRequired, assignee: $this->dave);
        $this->openAssignment($id, $this->dave);

        $response = $this->release($id, $this->dave);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('new', self::jsonAt($response, 'status'));
    }

    public function test_new_designer_pick_up_creates_second_assignment_row(): void
    {
        $id = $this->insertTask($this->anna);
        $this->clock->set(new DateTimeImmutable('2026-01-15 10:00:00', new DateTimeZone('UTC')));
        $this->pickUp($id, $this->dave);
        $this->clock->set(new DateTimeImmutable('2026-01-15 11:00:00', new DateTimeZone('UTC')));
        $this->release($id, $this->dave);

        // In the pool again, so the previous designer sees it, and the other designer takes it.
        self::assertSame(200, $this->request('GET', '/api/tasks/' . $id, as: $this->dave)->getStatusCode());
        $this->clock->set(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
        $response = $this->pickUp($id, $this->eve);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame(
            [
                [
                    'designer' => ['id' => $this->dave->id, 'displayName' => 'Dave Test'],
                    'assignedAt' => '2026-01-15T10:00:00.000Z',
                    'endedAt' => '2026-01-15T11:00:00.000Z',
                    'endedHow' => 'released',
                ],
                [
                    'designer' => ['id' => $this->eve->id, 'displayName' => 'Eve Test'],
                    'assignedAt' => '2026-01-15T12:00:00.000Z',
                    'endedAt' => null,
                    'endedHow' => null,
                ],
            ],
            self::jsonAt($response, 'assignments'),
        );
        self::assertSame(2, $this->countRows('assignments'));
        self::assertSame(
            [$this->dave->id, $this->eve->id],
            array_column($this->fetchRows('SELECT designer_id FROM assignments ORDER BY id'), 'designer_id'),
        );
        self::assertSame(1, $this->fetchValue('SELECT COUNT(*) FROM assignments WHERE task_id = ? AND ended_at IS NULL', [$id]));

        // The previous designer no longer sees the task, and the buyer and the manager see both rows.
        self::assertSame(404, $this->request('GET', '/api/tasks/' . $id, as: $this->dave)->getStatusCode());
        self::assertSame(404, $this->release($id, $this->dave)->getStatusCode());
        foreach ([$this->anna, $this->mia, $this->eve] as $viewer) {
            self::assertCount(2, self::jsonArray($this->request('GET', '/api/tasks/' . $id, as: $viewer), 'assignments'));
        }
    }

    public function test_only_assigned_designer_may_release(): void
    {
        $id = $this->insertTask($this->anna, status: TaskStatus::InProgress, assignee: $this->dave);
        $this->openAssignment($id, $this->dave);

        // Another designer cannot see an In Progress task of someone else: 404, as for GET.
        $other = $this->release($id, $this->eve);
        self::assertSame(404, $other->getStatusCode());
        $this->assertMatchesSpec($other);
        self::assertSame('not_found', self::jsonAt($other, 'error.code'));

        // The buyer and the manager see it but are not the assignee: 403.
        foreach ([$this->anna, $this->mia] as $viewer) {
            $response = $this->release($id, $viewer);
            self::assertSame(403, $response->getStatusCode());
            $this->assertMatchesSpec($response);
            self::assertSame('forbidden', self::jsonAt($response, 'error.code'));
        }

        self::assertSame('in_progress', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$id]));
        self::assertSame(0, $this->countRows('task_events'));
        self::assertNull($this->fetchValue('SELECT ended_at FROM assignments'));
    }

    public function test_designer_cannot_release_a_new_task(): void
    {
        $id = $this->insertTask($this->anna);

        $response = $this->release($id, $this->dave);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', self::jsonAt($response, 'error.code'));
    }

    public function test_release_from_under_review_is_409(): void
    {
        $id = $this->insertTask($this->anna, status: TaskStatus::UnderReview, assignee: $this->dave);
        $this->openAssignment($id, $this->dave);

        $response = $this->release($id, $this->dave);

        self::assertSame(409, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame('invalid_transition', self::jsonAt($response, 'error.code'));
        self::assertSame('under_review', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$id]));
        self::assertNull($this->fetchValue('SELECT ended_at FROM assignments'));
        self::assertSame(0, $this->countRows('task_events'));
    }

    public function test_rollback_leaves_everything_unchanged_when_the_event_cannot_be_recorded(): void
    {
        $id = $this->insertTask($this->anna, status: TaskStatus::InProgress, assignee: $this->dave);
        $this->openAssignment($id, $this->dave);
        $pdo = $this->database->pdo();
        $pdo->exec("CREATE TRIGGER fail_events BEFORE INSERT ON task_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boom'");

        try {
            $response = $this->release($id, $this->dave);
        } finally {
            $pdo->exec('DROP TRIGGER fail_events');
        }

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(
            [['status' => 'in_progress', 'assignee_id' => $this->dave->id, 'updated_at' => '2026-01-15 09:00:00']],
            $this->fetchRows('SELECT status, assignee_id, updated_at FROM tasks WHERE id = ?', [$id]),
        );
        self::assertNull($this->fetchValue('SELECT ended_at FROM assignments'));
        self::assertNull($this->fetchValue('SELECT ended_how FROM assignments'));
        self::assertSame(0, $this->countRows('task_events'));
    }

    public function test_the_database_allows_one_open_assignment_per_task(): void
    {
        $id = $this->insertTask($this->anna, status: TaskStatus::InProgress, assignee: $this->dave);
        $this->openAssignment($id, $this->dave);

        // A second open row for the same task is refused whoever the designer is...
        try {
            $this->openAssignment($id, $this->eve);
            self::fail('A second open assignment was accepted.');
        } catch (PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        // ...but closed rows pile up freely, and another task has its own open one.
        $pdo = $this->database->pdo();
        $pdo->exec("UPDATE assignments SET ended_at = '2026-01-15 10:00:00', ended_how = 'released'");
        $this->openAssignment($id, $this->eve);
        $pdo->exec("UPDATE assignments SET ended_at = '2026-01-15 11:00:00', ended_how = 'released' WHERE ended_at IS NULL");
        $this->openAssignment($id, $this->dave);
        $this->openAssignment($this->insertTask($this->anna), $this->dave);

        self::assertSame(4, $this->countRows('assignments'));
    }

    public function test_the_database_refuses_an_unknown_ended_how(): void
    {
        $id = $this->insertTask($this->anna);

        $this->expectException(PDOException::class);
        $this->database->pdo()->prepare("INSERT INTO assignments (task_id, designer_id, assigned_at, ended_at, ended_how) VALUES (?, ?, '2026-01-15 09:00:00', '2026-01-15 10:00:00', 'vanished')")
            ->execute([$id, $this->dave->id]);
    }

    private function pickUp(int $id, User $as): ResponseInterface
    {
        return $this->request('POST', '/api/tasks/' . $id . '/pickup', as: $as);
    }

    private function release(int $id, User $as): ResponseInterface
    {
        return $this->request('POST', '/api/tasks/' . $id . '/release', as: $as);
    }

    private function openAssignment(int $taskId, User $designer): void
    {
        $this->database->pdo()->prepare('INSERT INTO assignments (task_id, designer_id, assigned_at) VALUES (?, ?, ?)')
            ->execute([$taskId, $designer->id, $this->clock->now()->format('Y-m-d H:i:s.u')]);
    }
}
