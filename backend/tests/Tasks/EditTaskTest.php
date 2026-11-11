<?php

declare(strict_types=1);

namespace App\Tests\Tasks;

use App\History\FieldAuditLog;
use App\Identity\Role;
use App\Identity\User;
use App\Tasks\TaskStatus;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;

/**
 * PATCH /api/tasks/{id}: the creating buyer edits the brief while the task is open. Only what
 * actually changed is stored and audited, in one transaction with the update.
 */
final class EditTaskTest extends ApiTestCase
{
    private User $anna;

    private User $otto;

    private User $dave;

    private User $mia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna = $this->createUser('anna', Role::Buyer);
        $this->otto = $this->createUser('otto', Role::Buyer);
        $this->dave = $this->createUser('dave', Role::Designer);
        $this->mia = $this->createUser('mia', Role::Manager);
    }

    public function test_changed_fields_audited_with_old_and_new_and_actor(): void
    {
        $id = $this->insertTask($this->anna, 'Лото Клуб', geo: ['US']);
        $this->clock->set(new DateTimeImmutable('2026-01-15 10:30:00', new DateTimeZone('UTC')));

        $response = $this->edit($id, [
            // Sent in an order of its own: the audit keeps the order of the brief.
            'deadline' => '2026-02-02T13:00:00+03:00',
            'priority' => 'high',
            'description' => 'Новое описание',
            'quantity' => 5,
            'creativeType' => 'video',
            'geo' => ['US', 'DE', 'DE'],
            'offer' => '  Новый оффер ',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame($id, self::jsonAt($response, 'id'));
        self::assertSame('Новый оффер', self::jsonAt($response, 'offer'));
        self::assertSame(['DE', 'US'], self::jsonAt($response, 'geo'));
        self::assertSame('video', self::jsonAt($response, 'creativeType'));
        self::assertSame(5, self::jsonAt($response, 'quantity'));
        self::assertSame('Новое описание', self::jsonAt($response, 'description'));
        self::assertSame('high', self::jsonAt($response, 'priority'));
        self::assertSame('2026-02-02T10:00:00Z', self::jsonAt($response, 'deadlineAt'));

        $changes = [
            ['field' => 'offer', 'old' => 'Лото Клуб', 'new' => 'Новый оффер'],
            ['field' => 'geo', 'old' => ['US'], 'new' => ['DE', 'US']],
            ['field' => 'creativeType', 'old' => 'static_image', 'new' => 'video'],
            ['field' => 'quantity', 'old' => 1, 'new' => 5],
            ['field' => 'description', 'old' => 'Brief', 'new' => 'Новое описание'],
            ['field' => 'priority', 'old' => 'normal', 'new' => 'high'],
            ['field' => 'deadline', 'old' => '2026-02-01T10:00:00Z', 'new' => '2026-02-02T10:00:00Z'],
        ];

        $events = $this->fetchRows("SELECT id, task_id, actor_id, at FROM task_events WHERE type = 'brief_edited'");
        self::assertCount(1, $events);
        self::assertSame($id, $events[0]['task_id']);
        self::assertSame($this->anna->id, $events[0]['actor_id']);
        self::assertSame('2026-01-15 10:30:00.000000', $events[0]['at']);
        $stored = $this->fetchValue("SELECT payload FROM task_events WHERE type = 'brief_edited'");
        self::assertIsString($stored);
        self::assertSame(['changes' => $changes], json_decode($stored, true, 512, JSON_THROW_ON_ERROR));

        $eventId = $events[0]['id'];
        self::assertIsInt($eventId);
        self::assertSame($changes, $this->service(FieldAuditLog::class)->forEvent($eventId));

        // The task row, its geo and its update time.
        $row = $this->fetchRows('SELECT offer, creative_type, quantity, description, priority, deadline_at, created_at, updated_at FROM tasks WHERE id = ?', [$id])[0];
        self::assertSame([
            'offer' => 'Новый оффер',
            'creative_type' => 'video',
            'quantity' => 5,
            'description' => 'Новое описание',
            'priority' => 'high',
            'deadline_at' => '2026-02-02 10:00:00',
            'created_at' => '2026-01-15 09:00:00',
            'updated_at' => '2026-01-15 10:30:00',
        ], $row);
        self::assertSame(['DE', 'US'], array_column($this->fetchRows('SELECT code FROM task_geo WHERE task_id = ? ORDER BY code', [$id]), 'code'));
    }

    public function test_a_partial_edit_leaves_everything_else_untouched(): void
    {
        $id = $this->insertTask($this->anna, geo: ['US', 'DE']);
        $before = $this->fetchRows('SELECT offer, creative_type, quantity, description, priority, deadline_at, created_by, status FROM tasks WHERE id = ?', [$id]);
        $this->clock->set(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));

        $response = $this->edit($id, ['quantity' => 7]);

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame(7, self::jsonAt($response, 'quantity'));
        $after = $this->fetchRows('SELECT offer, creative_type, quantity, description, priority, deadline_at, created_by, status FROM tasks WHERE id = ?', [$id]);
        self::assertSame([...$before[0], 'quantity' => 7], $after[0]);
        self::assertSame(['DE', 'US'], array_column($this->fetchRows('SELECT code FROM task_geo WHERE task_id = ? ORDER BY code', [$id]), 'code'));
        self::assertSame('2026-01-15 12:00:00', $this->fetchValue('SELECT updated_at FROM tasks WHERE id = ?', [$id]));
        self::assertSame(
            [['field' => 'quantity', 'old' => 1, 'new' => 7]],
            self::jsonAt($this->request('GET', '/api/tasks/' . $id . '/history', as: $this->anna), 'events.0.payload.changes'),
        );
    }

    public function test_fields_sent_with_their_current_value_are_not_audited(): void
    {
        $id = $this->insertTask($this->anna, geo: ['US', 'DE']);

        $response = $this->edit($id, [
            'offer' => ' Лото Клуб ',
            'geo' => ['US', 'DE'],
            'creativeType' => 'static_image',
            'priority' => 'urgent',
            'deadline' => '2026-02-01T13:00:00+03:00',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $changes = $this->fetchValue("SELECT payload FROM task_events WHERE type = 'brief_edited'");
        self::assertSame('{"changes":[{"field":"priority","old":"normal","new":"urgent"}]}', $changes);
        self::assertSame(1, $this->countRows('task_field_changes'));
    }

    public function test_no_change_records_no_event(): void
    {
        $id = $this->insertTask($this->anna, geo: ['US', 'DE']);
        $this->clock->set(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));

        $response = $this->edit($id, [
            'offer' => 'Лото Клуб',
            'geo' => ['DE', 'US', 'US'],
            'quantity' => 1,
            'description' => 'Brief',
            'deadline' => '2026-02-01T10:00:00Z',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame($id, self::jsonAt($response, 'id'));
        self::assertSame(0, $this->countRows('task_events'));
        self::assertSame(0, $this->countRows('task_field_changes'));
        self::assertSame('2026-01-15 09:00:00', $this->fetchValue('SELECT updated_at FROM tasks WHERE id = ?', [$id]));
    }

    public function test_an_unchanged_deadline_need_not_be_in_the_future(): void
    {
        // Overdue already: the deadline passed on the 10th, now is the 15th.
        $id = $this->insertTask($this->anna, deadline: '2026-01-10 10:00:00');

        $response = $this->edit($id, ['deadline' => '2026-01-10T10:00:00Z', 'quantity' => 2]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('2026-01-10T10:00:00Z', self::jsonAt($response, 'deadlineAt'));
        self::assertSame(2, self::jsonAt($response, 'quantity'));

        // Any other deadline in the past is still refused.
        $other = $this->edit($id, ['deadline' => '2026-01-11T10:00:00Z']);
        self::assertSame(422, $other->getStatusCode());
        self::assertSame([['field' => 'deadline', 'code' => 'must_be_future', 'params' => []]], self::jsonAt($other, 'error.fields'));
    }

    #[DataProvider('closedStatuses')]
    public function test_completed_and_cancelled_are_409_task_not_editable(TaskStatus $status): void
    {
        $id = $this->insertTask($this->anna, status: $status, assignee: $status === TaskStatus::Completed ? $this->dave : null);

        $response = $this->edit($id, ['quantity' => 9]);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('{"error":{"code":"task_not_editable","fields":[]}}', (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame(1, $this->fetchValue('SELECT quantity FROM tasks WHERE id = ?', [$id]));
        self::assertSame(0, $this->countRows('task_events'));
    }

    /**
     * @return iterable<string, array{TaskStatus}>
     */
    public static function closedStatuses(): iterable
    {
        yield 'completed' => [TaskStatus::Completed];
        yield 'cancelled' => [TaskStatus::Cancelled];
    }

    #[DataProvider('openStatuses')]
    public function test_every_open_status_is_editable(TaskStatus $status): void
    {
        $id = $this->insertTask($this->anna, status: $status, assignee: $status === TaskStatus::New ? null : $this->dave);

        self::assertSame(200, $this->edit($id, ['quantity' => 9])->getStatusCode(), $status->value);
    }

    /**
     * @return iterable<string, array{TaskStatus}>
     */
    public static function openStatuses(): iterable
    {
        foreach ([TaskStatus::New, TaskStatus::InProgress, TaskStatus::UnderReview, TaskStatus::RevisionsRequired] as $status) {
            yield $status->value => [$status];
        }
    }

    public function test_designer_403_other_buyer_404(): void
    {
        $pool = $this->insertTask($this->anna, 'Pool');
        $assigned = $this->insertTask($this->anna, 'Assigned', TaskStatus::InProgress, $this->dave);

        // A designer who sees the task, and a manager, are refused; whatever they send.
        foreach ([[$this->dave, $pool], [$this->dave, $assigned], [$this->mia, $pool], [$this->mia, $assigned]] as [$user, $id]) {
            foreach ([['quantity' => 9], ['quantity' => 0], []] as $body) {
                $response = $this->edit($id, $body, $user);

                self::assertSame(403, $response->getStatusCode(), $user->username . ' ' . $id);
                self::assertSame('{"error":{"code":"forbidden","fields":[]}}', (string) $response->getBody());
                $this->assertMatchesSpec($response);
            }
        }

        // Somebody who cannot see the task, or a task that is not there, is a 404.
        $dina = $this->createUser('dina', Role::Designer);
        foreach ([[$this->otto, $pool], [$this->otto, $assigned], [$dina, $assigned], [$this->anna, 987_654]] as [$user, $id]) {
            $response = $this->edit($id, ['quantity' => 9], $user);

            self::assertSame(404, $response->getStatusCode(), $user->username . ' ' . $id);
            self::assertSame('{"error":{"code":"not_found","fields":[]}}', (string) $response->getBody());
            $this->assertMatchesSpec($response);
        }

        self::assertSame([1, 1], array_column($this->fetchRows('SELECT quantity FROM tasks ORDER BY id'), 'quantity'));
        self::assertSame(0, $this->countRows('task_events'));
    }

    public function test_a_session_is_needed_and_a_csrf_token_too(): void
    {
        $id = $this->insertTask($this->anna);

        $anonymous = $this->request('PATCH', '/api/tasks/' . $id, ['quantity' => 3]);
        self::assertSame(401, $anonymous->getStatusCode());
        $this->assertMatchesSpec($anonymous);

        [$token] = $this->startSession($this->anna);
        $noCsrf = $this->handle(self::withSession($this->buildRequest('PATCH', '/api/tasks/' . $id, ['quantity' => 3]), $token));
        self::assertSame(403, $noCsrf->getStatusCode());
        self::assertSame(1, $this->fetchValue('SELECT quantity FROM tasks WHERE id = ?', [$id]));
    }

    public function test_history_shows_brief_edited_with_changes(): void
    {
        $id = $this->insertTask($this->anna);
        $this->clock->set(new DateTimeImmutable('2026-01-15 11:00:00', new DateTimeZone('UTC')));
        $this->edit($id, ['offer' => 'Другой оффер', 'priority' => 'low']);

        foreach ([$this->anna, $this->mia] as $viewer) {
            $history = $this->request('GET', '/api/tasks/' . $id . '/history', as: $viewer);

            self::assertSame(200, $history->getStatusCode());
            $this->assertMatchesSpec($history);
            $event = self::jsonArray($history, 'events.0');
            self::assertSame('brief_edited', $event['type']);
            self::assertSame(['id' => $this->anna->id, 'displayName' => 'Anna Test'], $event['actor']);
            self::assertSame('2026-01-15T11:00:00.000Z', $event['at']);
            self::assertSame(
                ['changes' => [
                    ['field' => 'offer', 'old' => 'Лото Клуб', 'new' => 'Другой оффер'],
                    ['field' => 'priority', 'old' => 'normal', 'new' => 'low'],
                ]],
                $event['payload'],
            );
        }
    }

    public function test_emoji_cyrillic_and_html_description_edit_roundtrips(): void
    {
        $id = $this->insertTask($this->anna);
        $text = '🎰 <script>alert(1)</script> Лото';

        $response = $this->edit($id, ['description' => $text, 'offer' => '🎰 Лото <b>Клуб</b>']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($text, self::jsonAt($response, 'description'));
        self::assertStringNotContainsString('<script>', (string) $response->getBody());
        self::assertSame($text, $this->fetchValue('SELECT description FROM tasks WHERE id = ?', [$id]));
        self::assertSame($text, self::jsonAt($this->request('GET', '/api/tasks/' . $id, as: $this->anna), 'description'));
        self::assertSame(
            ['field' => 'description', 'old' => 'Brief', 'new' => $text],
            self::jsonAt($this->request('GET', '/api/tasks/' . $id . '/history', as: $this->anna), 'events.0.payload.changes.1'),
        );
        // The audit table holds the same text.
        $audited = $this->fetchValue("SELECT new_value FROM task_field_changes WHERE field = 'description'");
        self::assertIsString($audited);
        self::assertStringContainsString($text, $audited);
    }

    /**
     * @param array<string, mixed> $body
     * @param array{field: string, code: string, params: array<string, int>} $expected
     */
    #[DataProvider('invalidEdits')]
    public function test_invalid_values_give_the_same_codes_as_create(array $body, array $expected): void
    {
        $id = $this->insertTask($this->anna);

        $response = $this->edit($id, $body);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame('validation_failed', self::jsonAt($response, 'error.code'));
        self::assertSame([$expected], self::jsonAt($response, 'error.fields'));
        self::assertSame(0, $this->countRows('task_events'));
        self::assertSame(1, $this->fetchValue('SELECT quantity FROM tasks WHERE id = ?', [$id]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array{field: string, code: string, params: array<string, int>}}>
     */
    public static function invalidEdits(): iterable
    {
        yield 'offer too short' => [['offer' => 'a'], ['field' => 'offer', 'code' => 'too_short', 'params' => ['min' => 2]]];
        yield 'offer blank' => [['offer' => '  '], ['field' => 'offer', 'code' => 'required', 'params' => []]];
        yield 'offer null' => [['offer' => null], ['field' => 'offer', 'code' => 'required', 'params' => []]];
        yield 'quantity zero' => [['quantity' => 0], ['field' => 'quantity', 'code' => 'out_of_range', 'params' => ['min' => 1, 'max' => 100]]];
        yield 'quantity a string' => [['quantity' => '5'], ['field' => 'quantity', 'code' => 'invalid', 'params' => []]];
        yield 'description too long' => [['description' => str_repeat('x', 10001)], ['field' => 'description', 'code' => 'too_long', 'params' => ['max' => 10000]]];
        yield 'description blank' => [['description' => ''], ['field' => 'description', 'code' => 'required', 'params' => []]];
        yield 'unknown creative type' => [['creativeType' => 'hologram'], ['field' => 'creativeType', 'code' => 'invalid_choice', 'params' => []]];
        yield 'unknown priority' => [['priority' => 'asap'], ['field' => 'priority', 'code' => 'invalid_choice', 'params' => []]];
        yield 'geo empty' => [['geo' => []], ['field' => 'geo', 'code' => 'required', 'params' => []]];
        yield 'geo unknown code' => [['geo' => ['US', 'XX']], ['field' => 'geo', 'code' => 'invalid_choice', 'params' => []]];
        yield 'deadline in the past' => [['deadline' => '2026-01-14T09:00:00Z'], ['field' => 'deadline', 'code' => 'must_be_future', 'params' => []]];
        yield 'deadline words' => [['deadline' => 'tomorrow'], ['field' => 'deadline', 'code' => 'invalid', 'params' => []]];
        yield 'deadline beyond the horizon' => [['deadline' => '9999-12-31T23:59:59-01:00'], ['field' => 'deadline', 'code' => 'invalid', 'params' => []]];
        yield 'unknown field' => [['owner' => 7], ['field' => 'owner', 'code' => 'unknown_field', 'params' => []]];
        yield 'status is not editable' => [['status' => 'completed'], ['field' => 'status', 'code' => 'unknown_field', 'params' => []]];
        // Editing references is not supported; the field is as unknown as any other.
        yield 'references are not editable' => [['references' => []], ['field' => 'references', 'code' => 'unknown_field', 'params' => []]];
    }

    public function test_every_problem_is_reported_at_once(): void
    {
        $id = $this->insertTask($this->anna);

        $response = $this->edit($id, ['offer' => 'a', 'quantity' => 0]);

        self::assertSame(422, $response->getStatusCode());
        self::assertEquals(['offer' => 'too_short', 'quantity' => 'out_of_range'], array_column(self::jsonArray($response, 'error.fields'), 'code', 'field'));
    }

    public function test_a_body_without_a_known_field_is_422(): void
    {
        $id = $this->insertTask($this->anna);

        $empty = $this->edit($id, []);
        self::assertSame(422, $empty->getStatusCode(), (string) $empty->getBody());
        $this->assertMatchesSpec($empty);
        self::assertSame('validation_failed', self::jsonAt($empty, 'error.code'));

        [$token, $csrfToken] = $this->startSession($this->anna);
        foreach (['"text"', '42', 'null', '[1,2]'] as $json) {
            $request = self::withSession($this->buildRequest('PATCH', '/api/tasks/' . $id), $token, $csrfToken)
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream($json));
            $response = $this->handle($request);

            self::assertSame(422, $response->getStatusCode(), $json);
            $this->assertMatchesSpec($response);
        }
        self::assertSame(0, $this->countRows('task_events'));
    }

    public function test_a_failure_of_the_audit_insert_rolls_the_edit_back(): void
    {
        $id = $this->insertTask($this->anna, geo: ['US']);
        $pdo = $this->database->pdo();
        $pdo->exec("CREATE TRIGGER fail_field_changes BEFORE INSERT ON task_field_changes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boom'");
        $this->clock->set(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));

        try {
            $response = $this->edit($id, ['quantity' => 9, 'geo' => ['DE'], 'offer' => 'Другой']);
        } finally {
            $pdo->exec('DROP TRIGGER fail_field_changes');
        }

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(
            [['offer' => 'Лото Клуб', 'quantity' => 1, 'updated_at' => '2026-01-15 09:00:00']],
            $this->fetchRows('SELECT offer, quantity, updated_at FROM tasks WHERE id = ?', [$id]),
        );
        self::assertSame(['US'], array_column($this->fetchRows('SELECT code FROM task_geo WHERE task_id = ?', [$id]), 'code'));
        self::assertSame(0, $this->countRows('task_events'));
        self::assertSame(0, $this->countRows('task_field_changes'));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function edit(int $id, array $body, ?User $as = null): ResponseInterface
    {
        return $this->request('PATCH', '/api/tasks/' . $id, $body, $as ?? $this->anna);
    }
}
