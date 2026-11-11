<?php

declare(strict_types=1);

namespace App\Tests\Tasks;

use App\Identity\Role;
use App\Identity\User;
use App\Tasks\TaskStatus;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /api/tasks/{id}/pickup: a designer takes a New task. The task, the assignment and the
 * task_picked_up event change together or not at all.
 *
 * Order of the answers: no such task or not viewable -> 404; not a designer -> 403; the task is
 * taken by someone else -> 409 already_taken; any other status than New -> 409 invalid_transition.
 */
final class PickUpTest extends ApiTestCase
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

    public function test_designer_picks_up_new_task(): void
    {
        $id = $this->insertTask($this->anna);
        $this->clock->set(new DateTimeImmutable('2026-01-15 10:30:00.250000', new DateTimeZone('UTC')));

        $response = $this->pickUp($id, $this->dave);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame($id, self::jsonAt($response, 'id'));
        self::assertSame('in_progress', self::jsonAt($response, 'status'));
        self::assertSame(['id' => $this->dave->id, 'displayName' => 'Dave Test'], self::jsonAt($response, 'assignee'));
        self::assertSame(['release', 'submit', 'report_ai_usage'], self::jsonAt($response, 'allowedActions'));
        self::assertSame(
            [[
                'designer' => ['id' => $this->dave->id, 'displayName' => 'Dave Test'],
                'assignedAt' => '2026-01-15T10:30:00.250Z',
                'endedAt' => null,
                'endedHow' => null,
            ]],
            self::jsonAt($response, 'assignments'),
        );

        self::assertSame(
            [['status' => 'in_progress', 'assignee_id' => $this->dave->id, 'updated_at' => '2026-01-15 10:30:00']],
            $this->fetchRows('SELECT status, assignee_id, updated_at FROM tasks WHERE id = ?', [$id]),
        );

        $assignments = $this->fetchRows('SELECT id, task_id, designer_id, assigned_at, ended_at, ended_how FROM assignments');
        self::assertCount(1, $assignments);
        self::assertSame($id, $assignments[0]['task_id']);
        self::assertSame($this->dave->id, $assignments[0]['designer_id']);
        self::assertSame('2026-01-15 10:30:00.250000', $assignments[0]['assigned_at']);
        self::assertNull($assignments[0]['ended_at']);
        self::assertNull($assignments[0]['ended_how']);

        $events = $this->fetchRows('SELECT type, actor_id, at, payload FROM task_events');
        self::assertCount(1, $events);
        self::assertSame('task_picked_up', $events[0]['type']);
        self::assertSame($this->dave->id, $events[0]['actor_id']);
        self::assertSame('2026-01-15 10:30:00.250000', $events[0]['at']);
        self::assertSame(['assignmentId' => $assignments[0]['id']], json_decode(self::text($events[0]['payload']), true));
    }

    public function test_allowed_actions_follow_the_pick_up(): void
    {
        $id = $this->insertTask($this->anna);

        $before = $this->request('GET', '/api/tasks/' . $id, as: $this->dave);
        self::assertSame(['pick_up'], self::jsonAt($before, 'allowedActions'));
        self::assertSame([], self::jsonAt($before, 'assignments'));

        $this->pickUp($id, $this->dave);

        $after = $this->request('GET', '/api/tasks/' . $id, as: $this->dave);
        self::assertSame(['release', 'submit', 'report_ai_usage'], self::jsonAt($after, 'allowedActions'));

        $card = $this->request('GET', '/api/tasks', as: $this->dave);
        self::assertSame(['release', 'submit', 'report_ai_usage'], self::jsonAt($card, 'tasks.0.allowedActions'));
    }

    #[DataProvider('nonDesigners')]
    public function test_buyer_and_manager_403(string $who): void
    {
        $id = $this->insertTask($this->anna);
        $actor = $who === 'buyer' ? $this->anna : $this->mia;

        $response = $this->pickUp($id, $actor);

        self::assertSame(403, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame('forbidden', self::jsonAt($response, 'error.code'));
        $this->assertNothingChanged($id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonDesigners(): array
    {
        return ['the creating buyer, who can view the task' => ['buyer'], 'a manager, who can view it too' => ['manager']];
    }

    public function test_task_the_actor_cannot_view_is_404(): void
    {
        $otto = $this->createUser('otto', Role::Buyer);
        $id = $this->insertTask($this->anna);

        $response = $this->pickUp($id, $otto);

        self::assertSame(404, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame('not_found', self::jsonAt($response, 'error.code'));
        self::assertSame(404, $this->pickUp(999999, $this->dave)->getStatusCode());
        $this->assertNothingChanged($id);
    }

    public function test_designer_cannot_pick_up_a_cancelled_task_they_cannot_see(): void
    {
        $id = $this->insertTask($this->anna, status: TaskStatus::Cancelled);

        $response = $this->pickUp($id, $this->dave);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', self::jsonAt($response, 'error.code'));
    }

    #[DataProvider('statusesOfTheAssignedDesigner')]
    public function test_pick_up_non_new_is_409_invalid_transition(TaskStatus $status): void
    {
        $id = $this->insertTask($this->anna, status: $status, assignee: $this->dave);

        $response = $this->pickUp($id, $this->dave);

        self::assertSame(409, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame('invalid_transition', self::jsonAt($response, 'error.code'));
        self::assertSame(0, $this->countRows('assignments'));
        self::assertSame(0, $this->countRows('task_events'));
        self::assertSame($status->value, $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$id]));
    }

    /**
     * @return array<string, array{TaskStatus}>
     */
    public static function statusesOfTheAssignedDesigner(): array
    {
        return [
            'in progress' => [TaskStatus::InProgress],
            'under review' => [TaskStatus::UnderReview],
            'revisions required' => [TaskStatus::RevisionsRequired],
            'completed' => [TaskStatus::Completed],
        ];
    }

    public function test_second_designer_gets_409_already_taken(): void
    {
        $id = $this->insertTask($this->anna);
        self::assertSame(200, $this->pickUp($id, $this->dave)->getStatusCode());

        $response = $this->pickUp($id, $this->eve);

        self::assertSame(409, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame('already_taken', self::jsonAt($response, 'error.code'));
        self::assertSame($this->dave->id, $this->fetchValue('SELECT assignee_id FROM tasks WHERE id = ?', [$id]));
        self::assertSame(1, $this->countRows('assignments'));
        self::assertSame(1, $this->countRows('task_events'));
    }

    public function test_second_designer_gets_already_taken_when_the_task_has_moved_on(): void
    {
        $id = $this->insertTask($this->anna, status: TaskStatus::UnderReview, assignee: $this->dave);

        $response = $this->pickUp($id, $this->eve);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('already_taken', self::jsonAt($response, 'error.code'));
    }

    public function test_the_guarded_update_refuses_a_task_that_changed_after_the_read(): void
    {
        $id = $this->insertTask($this->anna);
        // Something that forgot the row lock gets in between the read and the update.
        $this->database->pdo()->exec(
            "CREATE TRIGGER take_before_pickup BEFORE UPDATE ON tasks FOR EACH ROW
             SET NEW.status = OLD.status, NEW.assignee_id = OLD.assignee_id, NEW.updated_at = OLD.updated_at",
        );

        try {
            $response = $this->pickUp($id, $this->dave);
        } finally {
            $this->database->pdo()->exec('DROP TRIGGER take_before_pickup');
        }

        // The trigger turns the guarded UPDATE into a no-op: zero rows changed is not a success.
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('already_taken', self::jsonAt($response, 'error.code'));
        self::assertSame(0, $this->countRows('assignments'));
        self::assertSame(0, $this->countRows('task_events'));
    }

    public function test_rollback_leaves_everything_unchanged_when_the_event_cannot_be_recorded(): void
    {
        $id = $this->insertTask($this->anna);
        $pdo = $this->database->pdo();
        $pdo->exec("CREATE TRIGGER fail_events BEFORE INSERT ON task_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boom'");

        try {
            $response = $this->pickUp($id, $this->dave);
        } finally {
            $pdo->exec('DROP TRIGGER fail_events');
        }

        self::assertSame(500, $response->getStatusCode());
        $this->assertNothingChanged($id);
    }

    public function test_pick_up_needs_the_csrf_token(): void
    {
        $id = $this->insertTask($this->anna);
        [$token] = $this->startSession($this->dave);

        $response = $this->handle(self::withSession($this->buildRequest('POST', '/api/tasks/' . $id . '/pickup'), $token));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', self::jsonAt($response, 'error.code'));
        $this->assertNothingChanged($id);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : self::fail('Expected a string column.');
    }

    private function pickUp(int $id, User $as): ResponseInterface
    {
        return $this->request('POST', '/api/tasks/' . $id . '/pickup', as: $as);
    }

    private function assertNothingChanged(int $id): void
    {
        self::assertSame(
            [['status' => 'new', 'assignee_id' => null, 'updated_at' => '2026-01-15 09:00:00']],
            $this->fetchRows('SELECT status, assignee_id, updated_at FROM tasks WHERE id = ?', [$id]),
        );
        self::assertSame(0, $this->countRows('assignments'));
        self::assertSame(0, $this->countRows('task_events'));
    }
}
