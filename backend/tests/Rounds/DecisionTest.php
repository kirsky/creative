<?php

declare(strict_types=1);

namespace App\Tests\Rounds;

use App\Identity\Role;
use App\Tasks\TaskStatus;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST /api/tasks/{id}/approve and /request-revisions: the creating buyer decides the round that
 * waits for review. The decision on the round, the status, the assignment, the event and updated_at
 * change together or not at all.
 *
 * Order of the answers: no such task or not viewable -> 404; viewable but not the creating buyer ->
 * 403; the task is not under review -> 409 invalid_transition; a bad body -> 422.
 */
final class DecisionTest extends RoundsTestCase
{
    private const string HOSTILE = '🎰 <script>alert(1)</script> Лото';

    public function test_request_revisions_requires_reason_422_required(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);

        foreach ([[], ['reason' => ''], ['reason' => "  \n\t "], ['reason' => null]] as $body) {
            $response = $this->requestRevisions($id, body: $body);

            self::assertSame(422, $response->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR));
            $this->assertMatchesSpec($response);
            self::assertSame('validation_failed', self::jsonAt($response, 'error.code'));
            self::assertSame([['field' => 'reason', 'code' => 'required', 'params' => []]], self::jsonAt($response, 'error.fields'));
        }

        // Nothing was decided or recorded.
        self::assertSame('under_review', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$id]));
        self::assertNull($this->fetchValue('SELECT decision FROM rounds'));
        self::assertSame(0, $this->countRows('task_events'));
    }

    public function test_reason_and_note_are_checked_for_type_and_length(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);

        $tooLong = $this->requestRevisions($id, body: ['reason' => str_repeat('я', 2001)]);
        self::assertSame(422, $tooLong->getStatusCode());
        $this->assertMatchesSpec($tooLong);
        self::assertSame([['field' => 'reason', 'code' => 'too_long', 'params' => ['max' => 2000]]], self::jsonAt($tooLong, 'error.fields'));

        $notText = $this->requestRevisions($id, body: ['reason' => 5]);
        self::assertSame(422, $notText->getStatusCode());
        self::assertSame([['field' => 'reason', 'code' => 'invalid', 'params' => []]], self::jsonAt($notText, 'error.fields'));

        $noteTooLong = $this->approve($id, body: ['note' => str_repeat('x', 2001)]);
        self::assertSame(422, $noteTooLong->getStatusCode());
        $this->assertMatchesSpec($noteTooLong);
        self::assertSame([['field' => 'note', 'code' => 'too_long', 'params' => ['max' => 2000]]], self::jsonAt($noteTooLong, 'error.fields'));

        $noteInvalid = $this->approve($id, body: ['note' => ['a']]);
        self::assertSame(422, $noteInvalid->getStatusCode());
        self::assertSame([['field' => 'note', 'code' => 'invalid', 'params' => []]], self::jsonAt($noteInvalid, 'error.fields'));

        // Exactly 2000 characters is fine.
        self::assertSame(200, $this->requestRevisions($id, body: ['reason' => str_repeat('я', 2000)])->getStatusCode());
        self::assertSame(str_repeat('я', 2000), $this->fetchValue('SELECT decision_reason FROM rounds'));
    }

    public function test_revisions_stores_reason_on_latest_round_and_sets_status(): void
    {
        $id = $this->taskOfDave(TaskStatus::UnderReview);
        $this->openAssignment($id);
        $this->insertRound($id, 1, decision: 'revisions_requested', reason: 'First remark');
        $this->insertRound($id, 2);
        $this->clock->set(new DateTimeImmutable('2026-01-15 13:30:45.125000', new DateTimeZone('UTC')));

        $response = $this->requestRevisions($id, body: ['reason' => self::HOSTILE]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame('revisions_required', self::jsonAt($response, 'status'));
        self::assertSame($this->dave->id, self::jsonAt($response, 'assignee.id'));
        self::assertSame(['edit_brief', 'cancel'], self::jsonAt($response, 'allowedActions'));
        self::assertSame(
            [
                ['number' => 1, 'decision' => 'revisions_requested', 'decisionReason' => 'First remark'],
                ['number' => 2, 'decision' => 'revisions_requested', 'decisionReason' => self::HOSTILE],
            ],
            array_map(
                static fn(mixed $round): array => is_array($round) ? array_intersect_key($round, array_flip(['number', 'decision', 'decisionReason'])) : [],
                self::jsonArray($response, 'rounds'),
            ),
        );
        self::assertSame('2026-01-15T13:30:45.125Z', self::jsonAt($response, 'rounds.1.decidedAt'));

        self::assertSame(
            [['status' => 'revisions_required', 'assignee_id' => $this->dave->id, 'updated_at' => '2026-01-15 13:30:45']],
            $this->fetchRows('SELECT status, assignee_id, updated_at FROM tasks WHERE id = ?', [$id]),
        );
        self::assertSame(
            [
                ['number' => 1, 'decision' => 'revisions_requested', 'decision_reason' => 'First remark', 'decided_at' => '2026-01-10 11:00:00.500000'],
                ['number' => 2, 'decision' => 'revisions_requested', 'decision_reason' => self::HOSTILE, 'decided_at' => '2026-01-15 13:30:45.125000'],
            ],
            $this->fetchRows('SELECT number, decision, decision_reason, decided_at FROM rounds ORDER BY number'),
        );

        // The designer stays assigned: the assignment is still open.
        self::assertSame(
            [['ended_at' => null, 'ended_how' => null]],
            $this->fetchRows('SELECT ended_at, ended_how FROM assignments'),
        );

        $events = $this->fetchRows('SELECT type, actor_id, at, payload FROM task_events');
        self::assertCount(1, $events);
        self::assertSame('revisions_requested', $events[0]['type']);
        self::assertSame($this->anna->id, $events[0]['actor_id']);
        self::assertSame('2026-01-15 13:30:45.125000', $events[0]['at']);
        self::assertSame(['round' => 2, 'reason' => self::HOSTILE], json_decode(self::text($events[0]['payload']), true));
    }

    public function test_approve_completes_and_closes_assignment_as_completed(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);
        $this->clock->set(new DateTimeImmutable('2026-01-16 08:15:00.750000', new DateTimeZone('UTC')));

        $response = $this->approve($id, body: ['note' => self::HOSTILE]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame('completed', self::jsonAt($response, 'status'));
        self::assertSame($this->dave->id, self::jsonAt($response, 'assignee.id'));
        self::assertSame([], self::jsonAt($response, 'allowedActions'));
        self::assertSame('approved', self::jsonAt($response, 'rounds.0.decision'));
        self::assertSame(self::HOSTILE, self::jsonAt($response, 'rounds.0.decisionReason'));
        self::assertSame('2026-01-16T08:15:00.750Z', self::jsonAt($response, 'rounds.0.decidedAt'));
        self::assertSame('completed', self::jsonAt($response, 'assignments.0.endedHow'));
        self::assertSame('2026-01-16T08:15:00.750Z', self::jsonAt($response, 'assignments.0.endedAt'));

        self::assertSame(
            [['status' => 'completed', 'assignee_id' => $this->dave->id, 'updated_at' => '2026-01-16 08:15:00']],
            $this->fetchRows('SELECT status, assignee_id, updated_at FROM tasks WHERE id = ?', [$id]),
        );
        self::assertSame(
            [['decision' => 'approved', 'decision_reason' => self::HOSTILE, 'decided_at' => '2026-01-16 08:15:00.750000']],
            $this->fetchRows('SELECT decision, decision_reason, decided_at FROM rounds'),
        );
        self::assertSame(
            [['ended_at' => '2026-01-16 08:15:00.750000', 'ended_how' => 'completed']],
            $this->fetchRows('SELECT ended_at, ended_how FROM assignments'),
        );

        $events = $this->fetchRows('SELECT type, actor_id, payload FROM task_events');
        self::assertSame(['task_approved'], array_column($events, 'type'));
        self::assertSame($this->anna->id, $events[0]['actor_id']);
        self::assertSame(['round' => 1, 'note' => self::HOSTILE], json_decode(self::text($events[0]['payload']), true));
    }

    public function test_approve_without_a_note_stores_null(): void
    {
        foreach ([[], ['note' => ''], ['note' => '   ']] as $body) {
            $id = $this->taskInStatus(TaskStatus::UnderReview);

            $response = $this->approve($id, body: $body);

            self::assertSame(200, $response->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR));
            self::assertNull(self::jsonAt($response, 'rounds.0.decisionReason'));
            self::assertNull($this->fetchValue('SELECT decision_reason FROM rounds WHERE task_id = ?', [$id]));
            self::assertSame(
                ['round' => 1, 'note' => null],
                json_decode(self::text($this->fetchValue("SELECT payload FROM task_events WHERE task_id = ? AND type = 'task_approved'", [$id])), true),
            );
        }
    }

    public function test_double_approve_is_409_and_one_completion_event(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);

        self::assertSame(200, $this->approve($id)->getStatusCode());
        $again = $this->approve($id);

        self::assertSame(409, $again->getStatusCode());
        $this->assertMatchesSpec($again);
        self::assertSame('{"error":{"code":"invalid_transition","fields":[]}}', (string) $again->getBody());
        self::assertSame(1, $this->fetchValue("SELECT COUNT(*) FROM task_events WHERE task_id = ? AND type = 'task_approved'", [$id]));
        self::assertSame(1, $this->countRows('task_events'));
        self::assertSame('completed', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$id]));

        // A decision on a task that is decided already is a conflict as well, whichever it is.
        self::assertSame(409, $this->requestRevisions($id)->getStatusCode());
        self::assertSame(1, $this->countRows('task_events'));
        self::assertSame('approved', $this->fetchValue('SELECT decision FROM rounds'));
    }

    /**
     * @return array<string, array{TaskStatus}>
     */
    public static function statusesThatAreNotUnderReview(): array
    {
        return [
            'new' => [TaskStatus::New],
            'in progress' => [TaskStatus::InProgress],
            'revisions required' => [TaskStatus::RevisionsRequired],
            'completed' => [TaskStatus::Completed],
            'cancelled' => [TaskStatus::Cancelled],
        ];
    }

    #[DataProvider('statusesThatAreNotUnderReview')]
    public function test_a_task_that_is_not_under_review_cannot_be_decided(TaskStatus $status): void
    {
        $id = $this->insertTask($this->anna, status: $status, assignee: $status === TaskStatus::New ? null : $this->dave);

        foreach ([$this->approve($id), $this->requestRevisions($id)] as $response) {
            self::assertSame(409, $response->getStatusCode());
            $this->assertMatchesSpec($response);
            self::assertSame('invalid_transition', self::jsonAt($response, 'error.code'));
        }

        self::assertSame($status->value, $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$id]));
        self::assertSame(0, $this->countRows('task_events'));
    }

    public function test_other_buyer_404(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);
        $otto = $this->createUser('otto', Role::Buyer);

        foreach ([$this->approve($id, $otto), $this->requestRevisions($id, $otto), $this->approve($id, $otto, ['note' => str_repeat('x', 3000)])] as $response) {
            self::assertSame(404, $response->getStatusCode());
            $this->assertMatchesSpec($response);
            self::assertSame('{"error":{"code":"not_found","fields":[]}}', (string) $response->getBody());
        }

        // A designer who cannot view the task gets the same answer as for any task that is not there.
        self::assertSame(404, $this->approve($id, $this->eve)->getStatusCode());
        self::assertSame(404, $this->approve(999999)->getStatusCode());
        $this->assertNothingDecided($id);
    }

    public function test_designer_and_manager_403(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);

        foreach ([$this->dave, $this->mia] as $user) {
            // Even a body that is invalid: the permission is decided before the body.
            foreach ([
                $this->approve($id, $user),
                $this->requestRevisions($id, $user),
                $this->requestRevisions($id, $user, []),
                $this->approve($id, $user, ['note' => str_repeat('x', 3000)]),
            ] as $response) {
                self::assertSame(403, $response->getStatusCode(), $user->username);
                $this->assertMatchesSpec($response);
                self::assertSame('{"error":{"code":"forbidden","fields":[]}}', (string) $response->getBody());
            }
        }

        $this->assertNothingDecided($id);
    }

    public function test_the_status_is_checked_before_the_body(): void
    {
        $id = $this->taskInStatus(TaskStatus::InProgress);

        $response = $this->requestRevisions($id, body: []);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('invalid_transition', self::jsonAt($response, 'error.code'));
    }

    public function test_multiple_revision_cycles(): void
    {
        $id = $this->taskOfDave();
        $this->openAssignment($id);
        $buyerActions = [];

        for ($round = 1; $round <= 3; ++$round) {
            $this->clock->set(new DateTimeImmutable('2026-01-1' . $round . ' 10:00:00', new DateTimeZone('UTC')));
            $submitted = $this->submit($id, [$this->uploadFor($this->dave, 'v' . $round . '.png')], extra: ['note' => 'Version ' . $round]);
            self::assertSame(201, $submitted->getStatusCode(), (string) $submitted->getBody());
            self::assertSame(['number' => $round], self::decodeJson($submitted));
            $buyerActions[] = self::jsonAt($this->request('GET', '/api/tasks/' . $id, as: $this->anna), 'allowedActions');

            $this->clock->set(new DateTimeImmutable('2026-01-1' . $round . ' 12:00:00', new DateTimeZone('UTC')));
            if ($round < 3) {
                $decided = $this->requestRevisions($id, body: ['reason' => 'Remark ' . $round]);
                self::assertSame('revisions_required', self::jsonAt($decided, 'status'));
            } else {
                $decided = $this->approve($id, body: ['note' => 'Good']);
                self::assertSame('completed', self::jsonAt($decided, 'status'));
            }
            self::assertSame(200, $decided->getStatusCode(), (string) $decided->getBody());
            $this->assertMatchesSpec($decided);
            $buyerActions[] = self::jsonAt($decided, 'allowedActions');

            // Until the approval, the designer is still assigned and the assignment is open.
            self::assertSame($round < 3 ? 1 : 0, $this->fetchValue('SELECT COUNT(*) FROM assignments WHERE task_id = ? AND ended_at IS NULL', [$id]));
            self::assertSame($this->dave->id, $this->fetchValue('SELECT assignee_id FROM tasks WHERE id = ?', [$id]));
        }

        $review = ['edit_brief', 'approve', 'request_revisions', 'cancel'];
        self::assertSame([$review, ['edit_brief', 'cancel'], $review, ['edit_brief', 'cancel'], $review, []], $buyerActions);

        self::assertSame(
            [
                ['number' => 1, 'decision' => 'revisions_requested', 'decision_reason' => 'Remark 1'],
                ['number' => 2, 'decision' => 'revisions_requested', 'decision_reason' => 'Remark 2'],
                ['number' => 3, 'decision' => 'approved', 'decision_reason' => 'Good'],
            ],
            $this->fetchRows('SELECT number, decision, decision_reason FROM rounds ORDER BY number'),
        );
        self::assertSame(
            ['round_submitted', 'revisions_requested', 'round_submitted', 'revisions_requested', 'round_submitted', 'task_approved'],
            array_column($this->fetchRows('SELECT type FROM task_events ORDER BY id'), 'type'),
        );
        $payloads = array_map(
            static fn(mixed $payload): mixed => json_decode(is_string($payload) ? $payload : '', true),
            array_column($this->fetchRows('SELECT payload FROM task_events ORDER BY id'), 'payload'),
        );
        self::assertSame(
            [1, 1, 2, 2, 3, 3],
            array_map(static fn(mixed $payload): mixed => is_array($payload) ? ($payload['round'] ?? null) : null, $payloads),
        );
        self::assertSame(
            [['ended_how' => 'completed']],
            $this->fetchRows('SELECT ended_how FROM assignments'),
        );

        // The finished task shows every decision.
        $detail = $this->request('GET', '/api/tasks/' . $id, as: $this->mia);
        self::assertSame(
            ['revisions_requested', 'revisions_requested', 'approved'],
            array_column(self::jsonArray($detail, 'rounds'), 'decision'),
        );
        self::assertSame(['Remark 1', 'Remark 2', 'Good'], array_column(self::jsonArray($detail, 'rounds'), 'decisionReason'));
    }

    public function test_a_completed_task_shows_round_decisions_to_everyone_who_may_view_it(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);
        self::assertSame(200, $this->approve($id, body: ['note' => 'Great work'])->getStatusCode());

        foreach ([$this->anna, $this->dave, $this->mia] as $viewer) {
            $response = $this->request('GET', '/api/tasks/' . $id, as: $viewer);

            self::assertSame(200, $response->getStatusCode(), $viewer->username);
            $this->assertMatchesSpec($response);
            self::assertSame('approved', self::jsonAt($response, 'rounds.0.decision'), $viewer->username);
            self::assertSame('Great work', self::jsonAt($response, 'rounds.0.decisionReason'), $viewer->username);
        }
    }

    public function test_an_inconsistent_task_is_a_server_error_that_changes_nothing(): void
    {
        // Under review but never submitted.
        $withoutRound = $this->taskOfDave(TaskStatus::UnderReview);
        $this->openAssignment($withoutRound);
        // Under review but its latest round is decided already.
        $decided = $this->taskOfDave(TaskStatus::UnderReview);
        $this->openAssignment($decided);
        $this->insertRound($decided, 1, decision: 'approved');

        foreach ([$withoutRound, $decided] as $id) {
            foreach ([$this->approve($id), $this->requestRevisions($id)] as $response) {
                self::assertSame(500, $response->getStatusCode());
            }
            self::assertSame('under_review', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$id]));
        }

        self::assertSame(0, $this->countRows('task_events'));
        self::assertSame(2, $this->fetchValue('SELECT COUNT(*) FROM assignments WHERE ended_at IS NULL'));
    }

    public function test_rollback_leaves_everything_unchanged_when_the_event_cannot_be_recorded(): void
    {
        $pdo = $this->database->pdo();

        foreach ([true, false] as $approve) {
            $id = $this->taskInStatus(TaskStatus::UnderReview);
            $pdo->exec("CREATE TRIGGER fail_events BEFORE INSERT ON task_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boom'");

            try {
                $response = $approve ? $this->approve($id, body: ['note' => 'ok']) : $this->requestRevisions($id);
            } finally {
                $pdo->exec('DROP TRIGGER fail_events');
            }

            self::assertSame(500, $response->getStatusCode());
            self::assertSame(
                [['status' => 'under_review', 'assignee_id' => $this->dave->id, 'updated_at' => '2026-01-15 09:00:00']],
                $this->fetchRows('SELECT status, assignee_id, updated_at FROM tasks WHERE id = ?', [$id]),
            );
            $this->assertNothingDecided($id);
        }

        self::assertSame(0, $this->countRows('task_events'));
    }

    private function assertNothingDecided(int $taskId): void
    {
        self::assertSame(
            [['decision' => null, 'decision_reason' => null, 'decided_at' => null]],
            $this->fetchRows('SELECT decision, decision_reason, decided_at FROM rounds WHERE task_id = ?', [$taskId]),
        );
        self::assertSame(
            [['ended_at' => null, 'ended_how' => null]],
            $this->fetchRows('SELECT ended_at, ended_how FROM assignments WHERE task_id = ?', [$taskId]),
        );
        self::assertSame(0, $this->fetchValue('SELECT COUNT(*) FROM task_events WHERE task_id = ?', [$taskId]));
        self::assertSame('under_review', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$taskId]));
    }
}
