<?php

declare(strict_types=1);

namespace App\Tests\Rounds;

use App\Identity\Role;
use App\Tasks\TaskStatus;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST /api/tasks/{id}/cancel: the creating buyer withdraws a task that is not finished. Nothing is
 * deleted: rounds, files, references and assignments stay, only the status and the open assignment
 * change.
 *
 * Order of the answers: no such task or not viewable -> 404; viewable but not the creating buyer ->
 * 403; completed or cancelled -> 409 invalid_transition; a bad body -> 422.
 */
final class CancelTest extends RoundsTestCase
{
    private const string HOSTILE = '🎰 <script>alert(1)</script> Лото';

    /**
     * @return array<string, array{TaskStatus, list<string>}> the status, and what the buyer may do with it before
     */
    public static function cancellableStatuses(): array
    {
        return [
            'new' => [TaskStatus::New, ['edit_brief', 'cancel']],
            'in progress' => [TaskStatus::InProgress, ['edit_brief', 'cancel']],
            'under review' => [TaskStatus::UnderReview, ['edit_brief', 'approve', 'request_revisions', 'cancel']],
            'revisions required' => [TaskStatus::RevisionsRequired, ['edit_brief', 'cancel']],
        ];
    }

    /**
     * @param list<string> $actionsBefore
     */
    #[DataProvider('cancellableStatuses')]
    public function test_cancel_allowed_from_each_of_four_statuses(TaskStatus $status, array $actionsBefore): void
    {
        $id = $this->taskInStatus($status);
        $before = $this->request('GET', '/api/tasks/' . $id, as: $this->anna);
        self::assertSame($actionsBefore, self::jsonAt($before, 'allowedActions'));
        $this->clock->set(new DateTimeImmutable('2026-01-16 09:30:15.500000', new DateTimeZone('UTC')));

        $response = $this->cancel($id, body: ['reason' => self::HOSTILE]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame('cancelled', self::jsonAt($response, 'status'));
        self::assertSame([], self::jsonAt($response, 'allowedActions'));
        self::assertSame($id, self::jsonAt($response, 'id'));

        self::assertSame(
            [['status' => 'cancelled', 'updated_at' => '2026-01-16 09:30:15']],
            $this->fetchRows('SELECT status, updated_at FROM tasks WHERE id = ?', [$id]),
        );

        $events = $this->fetchRows('SELECT type, actor_id, at, payload FROM task_events');
        self::assertSame(['task_cancelled'], array_column($events, 'type'));
        self::assertSame($this->anna->id, $events[0]['actor_id']);
        self::assertSame('2026-01-16 09:30:15.500000', $events[0]['at']);
        self::assertSame(['reason' => self::HOSTILE], json_decode(self::text($events[0]['payload']), true));

        if ($status === TaskStatus::New) {
            // Nobody held it: there is no assignment to close, and none is made up.
            self::assertSame(0, $this->countRows('assignments'));
        } else {
            self::assertSame(
                [['ended_at' => '2026-01-16 09:30:15.500000', 'ended_how' => 'cancelled']],
                $this->fetchRows('SELECT ended_at, ended_how FROM assignments'),
            );
            self::assertSame('cancelled', self::jsonAt($response, 'assignments.0.endedHow'));
        }
    }

    public function test_cancel_without_a_reason_records_null(): void
    {
        foreach ([[], ['reason' => ''], ['reason' => " \n"], ['reason' => null]] as $body) {
            $id = $this->insertTask($this->anna);

            $response = $this->cancel($id, body: $body);

            self::assertSame(200, $response->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR));
            self::assertSame(
                ['reason' => null],
                json_decode(self::text($this->fetchValue("SELECT payload FROM task_events WHERE task_id = ? AND type = 'task_cancelled'", [$id])), true),
            );
        }
    }

    public function test_cancel_checks_the_reason_after_the_permission(): void
    {
        $id = $this->insertTask($this->anna);

        $tooLong = $this->cancel($id, body: ['reason' => str_repeat('я', 2001)]);
        self::assertSame(422, $tooLong->getStatusCode());
        $this->assertMatchesSpec($tooLong);
        self::assertSame([['field' => 'reason', 'code' => 'too_long', 'params' => ['max' => 2000]]], self::jsonAt($tooLong, 'error.fields'));

        $invalid = $this->cancel($id, body: ['reason' => 12]);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame([['field' => 'reason', 'code' => 'invalid', 'params' => []]], self::jsonAt($invalid, 'error.fields'));

        // Not the buyer's task, or not the buyer: the body does not matter.
        self::assertSame(404, $this->cancel($id, $this->createUser('otto', Role::Buyer), ['reason' => 12])->getStatusCode());
        self::assertSame(403, $this->cancel($id, $this->mia, ['reason' => 12])->getStatusCode());

        self::assertSame('new', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$id]));
        self::assertSame(0, $this->countRows('task_events'));
        self::assertSame(200, $this->cancel($id, body: ['reason' => str_repeat('я', 2000)])->getStatusCode());
    }

    public function test_cancel_completed_or_cancelled_is_409(): void
    {
        $completed = $this->taskInStatus(TaskStatus::UnderReview);
        self::assertSame(200, $this->approve($completed)->getStatusCode());
        $cancelled = $this->taskInStatus(TaskStatus::InProgress);
        self::assertSame(200, $this->cancel($cancelled)->getStatusCode());
        $events = $this->countRows('task_events');

        foreach ([$completed, $cancelled] as $id) {
            $response = $this->cancel($id, body: ['reason' => 'again']);

            self::assertSame(409, $response->getStatusCode());
            $this->assertMatchesSpec($response);
            self::assertSame('{"error":{"code":"invalid_transition","fields":[]}}', (string) $response->getBody());
        }

        self::assertSame('completed', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$completed]));
        self::assertSame('cancelled', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$cancelled]));
        self::assertSame($events, $this->countRows('task_events'));
        self::assertSame(1, $this->fetchValue("SELECT COUNT(*) FROM task_events WHERE type = 'task_cancelled'"));
    }

    public function test_cancel_closes_open_assignment_and_keeps_data(): void
    {
        $id = $this->taskOfDave(TaskStatus::RevisionsRequired);
        $pdo = $this->database->pdo();
        $pdo->prepare("INSERT INTO task_references (task_id, kind, url, file_id) VALUES (?, 'url', 'https://example.com', NULL)")->execute([$id]);
        // An earlier stretch of work by another designer, already closed, and the one that is open.
        $pdo->prepare("INSERT INTO assignments (task_id, designer_id, assigned_at, ended_at, ended_how) VALUES (?, ?, '2026-01-05 10:00:00', '2026-01-06 10:00:00', 'released')")
            ->execute([$id, $this->eve->id]);
        $this->openAssignment($id);
        $file = $this->uploadFor($this->dave);
        $this->insertRound($id, 1, [$file], decision: 'revisions_requested', reason: 'Logo');
        $fileCount = $this->countRows('files');

        $response = $this->cancel($id, body: ['reason' => 'Campaign dropped']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            [['designer_id' => $this->eve->id, 'ended_how' => 'released'], ['designer_id' => $this->dave->id, 'ended_how' => 'cancelled']],
            $this->fetchRows('SELECT designer_id, ended_how FROM assignments ORDER BY id'),
        );
        self::assertSame(1, $this->countRows('rounds'));
        self::assertSame(1, $this->countRows('deliverables'));
        self::assertSame($fileCount, $this->countRows('files'));
        self::assertSame(1, $this->countRows('file_attachments'));
        self::assertSame(1, $this->countRows('task_references'));
        self::assertSame(1, $this->countRows('task_geo'));
        self::assertSame(
            [['assignee_id' => $this->dave->id]],
            $this->fetchRows('SELECT assignee_id FROM tasks WHERE id = ?', [$id]),
        );
        // The round that was sent back keeps its decision.
        self::assertSame(
            [['decision' => 'revisions_requested', 'decision_reason' => 'Logo']],
            $this->fetchRows('SELECT decision, decision_reason FROM rounds'),
        );

        // Whoever could read the task and its deliverables still can.
        foreach ([$this->anna, $this->dave, $this->mia] as $viewer) {
            self::assertSame(200, $this->request('GET', '/api/tasks/' . $id, as: $viewer)->getStatusCode(), $viewer->username);
            self::assertSame(200, $this->request('GET', '/api/files/' . $file, as: $viewer)->getStatusCode(), $viewer->username);
        }
        self::assertCount(2, self::jsonArray($this->request('GET', '/api/tasks/' . $id, as: $this->anna), 'assignments'));
    }

    public function test_cancel_keeps_an_undecided_round_as_it_is(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);

        self::assertSame(200, $this->cancel($id)->getStatusCode());

        self::assertSame(
            [['decision' => null, 'decision_reason' => null, 'decided_at' => null]],
            $this->fetchRows('SELECT decision, decision_reason, decided_at FROM rounds'),
        );
        self::assertSame(1, $this->countRows('deliverables'));
    }

    public function test_only_the_creating_buyer_may_cancel(): void
    {
        $id = $this->taskInStatus(TaskStatus::InProgress);
        $otto = $this->createUser('otto', Role::Buyer);

        $other = $this->cancel($id, $otto);
        self::assertSame(404, $other->getStatusCode());
        $this->assertMatchesSpec($other);
        self::assertSame('not_found', self::jsonAt($other, 'error.code'));
        self::assertSame(404, $this->cancel($id, $this->eve)->getStatusCode());
        self::assertSame(404, $this->cancel(999999)->getStatusCode());

        foreach ([$this->dave, $this->mia] as $user) {
            $response = $this->cancel($id, $user);
            self::assertSame(403, $response->getStatusCode(), $user->username);
            $this->assertMatchesSpec($response);
            self::assertSame('forbidden', self::jsonAt($response, 'error.code'));
        }

        // A designer looking at a New task in the pool may view it but not cancel it.
        $new = $this->insertTask($this->anna);
        self::assertSame(403, $this->cancel($new, $this->eve)->getStatusCode());

        self::assertSame('in_progress', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$id]));
        self::assertSame('new', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$new]));
        self::assertSame(0, $this->countRows('task_events'));
        self::assertNull($this->fetchValue('SELECT ended_at FROM assignments'));
    }

    public function test_cancelled_task_is_not_picked_up_released_or_submitted_to(): void
    {
        $id = $this->taskInStatus(TaskStatus::InProgress);
        self::assertSame(200, $this->cancel($id)->getStatusCode());

        self::assertSame(409, $this->request('POST', '/api/tasks/' . $id . '/release', as: $this->dave)->getStatusCode());
        self::assertSame(409, $this->submit($id, [$this->uploadFor($this->dave)])->getStatusCode());
        // Another designer cannot even see it any more.
        self::assertSame(404, $this->request('POST', '/api/tasks/' . $id . '/pickup', as: $this->eve)->getStatusCode());
        self::assertSame('cancelled', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$id]));
    }

    public function test_rollback_leaves_everything_unchanged_when_the_event_cannot_be_recorded(): void
    {
        $id = $this->taskInStatus(TaskStatus::InProgress);
        $pdo = $this->database->pdo();
        $pdo->exec("CREATE TRIGGER fail_events BEFORE INSERT ON task_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boom'");

        try {
            $response = $this->cancel($id, body: ['reason' => 'x']);
        } finally {
            $pdo->exec('DROP TRIGGER fail_events');
        }

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(
            [['status' => 'in_progress', 'assignee_id' => $this->dave->id, 'updated_at' => '2026-01-15 09:00:00']],
            $this->fetchRows('SELECT status, assignee_id, updated_at FROM tasks WHERE id = ?', [$id]),
        );
        self::assertSame([['ended_at' => null, 'ended_how' => null]], $this->fetchRows('SELECT ended_at, ended_how FROM assignments'));
        self::assertSame(0, $this->countRows('task_events'));
    }
}
