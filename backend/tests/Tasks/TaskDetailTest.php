<?php

declare(strict_types=1);

namespace App\Tests\Tasks;

use App\History\TaskEventLog;
use App\History\TaskEventType;
use App\Identity\Role;
use App\Identity\User;
use App\Tasks\TaskStatus;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;

/**
 * GET /api/tasks/{id}, GET /api/tasks/{id}/history and GET /api/options: who may see what, and the
 * shape of the answers.
 */
final class TaskDetailTest extends ApiTestCase
{
    private User $anna;

    private User $otto;

    private User $dave;

    private User $dina;

    private User $mia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna = $this->createUser('anna', Role::Buyer);
        $this->otto = $this->createUser('otto', Role::Buyer);
        $this->dave = $this->createUser('dave', Role::Designer);
        $this->dina = $this->createUser('dina', Role::Designer);
        $this->mia = $this->createUser('mia', Role::Manager);
    }

    public function test_detail_has_the_card_and_the_brief(): void
    {
        $id = $this->insertTask($this->anna, 'Лото Клуб', TaskStatus::InProgress, $this->dave, '2026-01-20 10:00:00', 'urgent', 'html5_banner', ['US', 'WW']);
        $this->database->pdo()->prepare('INSERT INTO task_references (task_id, kind, url) VALUES (?, ?, ?)')->execute([$id, 'url', 'https://example.com/ref']);

        $response = $this->request('GET', '/api/tasks/' . $id, as: $this->anna);

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame([
            'id' => $id,
            'offer' => 'Лото Клуб',
            'geo' => ['US', 'WW'],
            'creativeType' => 'html5_banner',
            'quantity' => 1,
            'priority' => 'urgent',
            'deadlineAt' => '2026-01-20T10:00:00Z',
            'status' => 'in_progress',
            'createdBy' => ['id' => $this->anna->id, 'displayName' => 'Anna Test'],
            'assignee' => ['id' => $this->dave->id, 'displayName' => 'Dave Test'],
            'roundCount' => 0,
            'creditsTotal' => '0.00',
            'isOverdue' => false,
            'isReturning' => false,
            'allowedActions' => ['edit_brief', 'cancel'],
            'description' => 'Brief',
            'references' => [['type' => 'url', 'url' => 'https://example.com/ref']],
            'assignments' => [],
            'rounds' => [],
            'metrics' => ['queueWaitSeconds' => 0, 'cycleSeconds' => null, 'reviewSeconds' => 0, 'revisionTurnaroundSeconds' => 0],
        ], self::decodeJson($response));
    }

    public function test_other_buyers_task_is_404(): void
    {
        $id = $this->insertTask($this->anna);

        foreach (['/api/tasks/' . $id, '/api/tasks/' . $id . '/history'] as $path) {
            $response = $this->request('GET', $path, as: $this->otto);

            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertSame('{"error":{"code":"not_found","fields":[]}}', (string) $response->getBody());
            $this->assertMatchesSpec($response);
        }
    }

    public function test_a_task_that_does_not_exist_is_the_same_404(): void
    {
        foreach (['/api/tasks/987654', '/api/tasks/987654/history', '/api/tasks/99999999999999999999'] as $path) {
            $response = $this->request('GET', $path, as: $this->mia);

            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertSame('{"error":{"code":"not_found","fields":[]}}', (string) $response->getBody());
        }
    }

    public function test_designers_and_manager_see_what_the_policy_lets_them_see(): void
    {
        $pool = $this->insertTask($this->anna, 'Pool');
        $dave = $this->insertTask($this->anna, 'Dave', TaskStatus::InProgress, $this->dave);

        $expected = [
            [$this->dave, $pool, 200], [$this->dina, $pool, 200], [$this->mia, $pool, 200],
            [$this->dave, $dave, 200], [$this->dina, $dave, 404], [$this->mia, $dave, 200],
        ];
        foreach ($expected as [$user, $id, $status]) {
            self::assertSame($status, $this->request('GET', '/api/tasks/' . $id, as: $user)->getStatusCode(), $user->username . ' ' . $id);
            self::assertSame($status, $this->request('GET', '/api/tasks/' . $id . '/history', as: $user)->getStatusCode(), $user->username . ' ' . $id);
        }
    }

    public function test_allowed_actions_follow_the_viewer(): void
    {
        $id = $this->insertTask($this->anna);

        $actions = [];
        foreach ([$this->anna, $this->dave, $this->mia] as $user) {
            $actions[$user->username] = self::jsonAt($this->request('GET', '/api/tasks/' . $id, as: $user), 'allowedActions');
        }

        self::assertSame(['anna' => ['edit_brief', 'cancel'], 'dave' => ['pick_up'], 'mia' => []], $actions);
    }

    public function test_history_endpoint_returns_events_in_order(): void
    {
        $id = $this->insertTask($this->anna);
        $log = $this->service(TaskEventLog::class);
        $this->clock->set(new DateTimeImmutable('2026-01-15T09:00:00Z'));
        $log->record($id, TaskEventType::TaskCreated, $this->anna);
        $this->clock->advance('PT5M');
        $log->record($id, TaskEventType::TaskPickedUp, $this->dave, ['assignmentId' => 4]);
        $this->clock->advance('PT1H');
        $log->record($id, TaskEventType::TaskCancelled, $this->anna, ['reason' => 'Передумали 🎰']);
        $other = $this->insertTask($this->otto);
        $log->record($other, TaskEventType::TaskCreated, $this->otto);

        $response = $this->request('GET', '/api/tasks/' . $id . '/history', as: $this->mia);

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        $events = self::jsonAt($response, 'events');
        self::assertIsArray($events);
        self::assertSame(
            ['task_created', 'task_picked_up', 'task_cancelled'],
            array_column($events, 'type'),
        );
        self::assertSame(
            [
                'type' => 'task_picked_up',
                'actor' => ['id' => $this->dave->id, 'displayName' => 'Dave Test'],
                'at' => '2026-01-15T09:05:00.000Z',
                'payload' => ['assignmentId' => 4],
            ],
            array_diff_key(self::jsonArray($response, 'events.1'), ['id' => 0]),
        );
        self::assertSame(['reason' => 'Передумали 🎰'], self::jsonAt($response, 'events.2.payload'));
        // An empty payload is an object on the wire, not a list.
        self::assertStringContainsString('"payload":{}', (string) $response->getBody());
    }

    public function test_history_of_a_task_without_events_is_an_empty_list(): void
    {
        $id = $this->insertTask($this->anna);

        $response = $this->request('GET', '/api/tasks/' . $id . '/history', as: $this->anna);

        self::assertSame('{"events":[]}', (string) $response->getBody());
        $this->assertMatchesSpec($response);
    }

    public function test_options_list_the_codes_for_any_role(): void
    {
        foreach ([$this->anna, $this->dave, $this->mia] as $user) {
            $response = $this->request('GET', '/api/options', as: $user);

            self::assertSame(200, $response->getStatusCode(), $user->username);
            $this->assertMatchesSpec($response);
            self::assertSame(['static_image', 'video', 'carousel', 'animated_gif', 'html5_banner', 'other'], self::jsonAt($response, 'creativeTypes'));
            self::assertSame(['low', 'normal', 'high', 'urgent'], self::jsonAt($response, 'priorities'));
            self::assertSame(['WW', 'EU', 'LATAM', 'MENA', 'CIS'], self::jsonAt($response, 'regions'));
            self::assertCount(249, self::jsonArray($response, 'countries'));
            self::assertContains('UA', self::jsonArray($response, 'countries'));
        }
    }

    public function test_options_need_a_session(): void
    {
        self::assertSame(401, $this->request('GET', '/api/options')->getStatusCode());
    }
}
