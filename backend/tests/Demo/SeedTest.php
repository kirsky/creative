<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use App\Demo\Cli\SeedCommand;
use App\Demo\Cli\SeedIfEmptyCommand;
use App\Identity\Role;
use App\Identity\UserRepository;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * demo:seed and what it leaves behind: accounts, the 16 tasks, and a database the application's own
 * rules would accept (the seed goes through the real services, so these tests check the result as a
 * whole rather than re-testing the services).
 */
#[SharedSeed]
final class SeedTest extends DemoTestCase
{
    private const array ACCOUNTS = [
        'dmitry' => ['Dmitry P.', Role::Buyer],
        'elena' => ['Elena V.', Role::Buyer],
        'anna' => ['Anna K.', Role::Designer],
        'igor' => ['Igor S.', Role::Designer],
        'maria' => ['Maria T.', Role::Designer],
        'manager' => ['Olga M.', Role::Manager],
    ];

    public function test_creates_six_accounts_with_null_password_hash_when_missing(): void
    {
        $this->sharedSeed();

        self::assertSame(6, $this->countRows('users'));
        $users = $this->service(UserRepository::class);
        foreach (self::ACCOUNTS as $username => [$name, $role]) {
            $user = $users->findByUsername($username);
            self::assertNotNull($user, $username);
            self::assertSame($name, $user->displayName);
            self::assertSame($role, $user->role);
            self::assertSame('en', $user->locale);
            self::assertNull($user->passwordHash, $username . ' must not be able to log in');
        }
        self::assertSame(0, self::integer($this->fetchValue('SELECT COUNT(*) FROM users WHERE password_hash IS NOT NULL')));
        self::assertSame(0, $this->countRows('security_events'));
        self::assertSame(0, $this->countRows('login_attempts'));
    }

    public function test_status_counts_are_3_3_2_2_4_2(): void
    {
        $this->sharedSeed();

        self::assertSame(
            ['new' => 3, 'in_progress' => 3, 'under_review' => 2, 'revisions_required' => 2, 'completed' => 4, 'cancelled' => 2],
            $this->statusCounts(),
        );
        self::assertSame(16, $this->countRows('tasks'));
    }

    public function test_at_least_two_overdue_one_repicked_up_and_multi_round_tasks_exist(): void
    {
        $this->sharedSeed();
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $overdue = $this->fetchRows(
            "SELECT offer, status FROM tasks WHERE deadline_at < ? AND status NOT IN ('completed', 'cancelled') ORDER BY deadline_at",
            [$now],
        );
        self::assertCount(2, $overdue);
        self::assertSame(['Мега Спин', 'Лото Клуб'], array_column($overdue, 'offer'));

        // Released by one designer and picked up by another: two assignments, the first released, the second open.
        $repicked = $this->fetchRows('SELECT task_id FROM assignments GROUP BY task_id HAVING COUNT(*) = 2');
        self::assertCount(1, $repicked);
        $taskId = self::integer($repicked[0]['task_id']);
        $assignments = $this->fetchRows('SELECT designer_id, ended_how FROM assignments WHERE task_id = ? ORDER BY id', [$taskId]);
        self::assertSame('released', $assignments[0]['ended_how']);
        self::assertNull($assignments[1]['ended_how']);
        self::assertNotSame($assignments[0]['designer_id'], $assignments[1]['designer_id']);
        self::assertSame('in_progress', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$taskId]));
        self::assertSame('revisions_requested', $this->fetchValue('SELECT decision FROM rounds WHERE task_id = ? ORDER BY number DESC LIMIT 1', [$taskId]));

        // The returning task: New again, the latest round sent back, nobody holds it.
        $returning = $this->fetchRows(
            "SELECT t.id FROM tasks t WHERE t.status = 'new' AND t.assignee_id IS NULL
               AND (SELECT r.decision FROM rounds r WHERE r.task_id = t.id ORDER BY r.number DESC LIMIT 1) = 'revisions_requested'",
        );
        self::assertCount(1, $returning);
        $board = self::jsonArray($this->request('GET', '/api/tasks', as: $this->service(UserRepository::class)->findByUsername('manager')), 'tasks');
        $flagged = array_filter($board, static fn(mixed $task): bool => is_array($task) && ($task['isReturning'] ?? false) === true);
        self::assertCount(1, $flagged);

        // Several tasks went through two or more rounds.
        $multi = $this->fetchRows('SELECT task_id FROM rounds GROUP BY task_id HAVING COUNT(*) >= 2');
        self::assertGreaterThanOrEqual(3, count($multi));

        // Both cancellations: one while New (never held), one in the middle of the work.
        $cancelled = $this->fetchRows(
            "SELECT t.id, (SELECT COUNT(*) FROM assignments a WHERE a.task_id = t.id) AS held FROM tasks t WHERE t.status = 'cancelled' ORDER BY held",
        );
        self::assertSame([0, 1], array_map(self::integer(...), array_column($cancelled, 'held')));
    }

    public function test_spread_over_the_window_with_both_buyers_all_designers_and_four_ai_providers(): void
    {
        $this->sharedSeed();

        self::assertSame(['dmitry', 'elena'], $this->column('SELECT DISTINCT u.username FROM tasks t JOIN users u ON u.id = t.created_by ORDER BY 1'));
        self::assertSame(['anna', 'igor', 'maria'], $this->column('SELECT DISTINCT u.username FROM assignments a JOIN users u ON u.id = a.designer_id ORDER BY 1'));
        self::assertGreaterThanOrEqual(4, count($this->column('SELECT DISTINCT provider_id FROM ai_usage')));

        $now = $this->clock->now();
        $first = new DateTimeImmutable(self::text($this->fetchValue('SELECT MIN(at) FROM task_events')), new DateTimeZone('UTC'));
        $last = new DateTimeImmutable(self::text($this->fetchValue('SELECT MAX(at) FROM task_events')), new DateTimeZone('UTC'));
        self::assertGreaterThanOrEqual($now->sub(new DateInterval('P14D')), $first);
        self::assertLessThan($now->sub(new DateInterval('P12D')), $first, 'the history starts near the beginning of the window');
        self::assertLessThanOrEqual($now, $last);
        self::assertGreaterThan($now->sub(new DateInterval('PT12H')), $last, 'something happened today');
    }

    public function test_traced_task_event_order_and_180_credits(): void
    {
        $this->sharedSeed();

        $task = $this->fetchRows(
            "SELECT t.id, t.status, t.quantity, t.creative_type, GROUP_CONCAT(g.code) AS geo, b.username AS buyer, d.username AS designer
             FROM tasks t JOIN users b ON b.id = t.created_by JOIN users d ON d.id = t.assignee_id JOIN task_geo g ON g.task_id = t.id
             WHERE t.offer = 'Lucky Spin' GROUP BY t.id",
        );
        self::assertCount(1, $task);
        self::assertSame(['completed', 2, 'video', 'IT', 'dmitry', 'anna'], [$task[0]['status'], self::integer($task[0]['quantity']), $task[0]['creative_type'], $task[0]['geo'], $task[0]['buyer'], $task[0]['designer']]);
        $id = self::integer($task[0]['id']);

        $events = $this->fetchRows('SELECT type, payload, at FROM task_events WHERE task_id = ? ORDER BY at, id', [$id]);
        $types = array_map(static fn(array $event): string => self::text($event['type']), $events);

        // The six steps of the story, in this order; the AI usage events sit right after the round that reported them.
        self::assertSame(
            ['task_created', 'task_picked_up', 'round_submitted', 'revisions_requested', 'round_submitted', 'task_approved'],
            array_values(array_filter($types, static fn(string $type): bool => $type !== 'ai_usage_changed')),
        );
        self::assertSame(
            ['task_created', 'task_picked_up', 'round_submitted', 'ai_usage_changed', 'revisions_requested', 'round_submitted', 'ai_usage_changed', 'task_approved'],
            $types,
        );

        $payload = static fn(int $index): array => self::decodePayload($events[$index]['payload']);
        self::assertSame(1, $payload(2)['round']);
        self::assertGreaterThanOrEqual(2, $payload(2)['fileCount']);
        self::assertSame(1, $payload(4)['round']);
        self::assertSame('Сделать хук короче, логотип крупнее', $payload(4)['reason']);
        self::assertSame(2, $payload(5)['round']);
        self::assertGreaterThanOrEqual(1, $payload(5)['fileCount']);
        self::assertSame(2, $payload(7)['round']);

        // AI usage in both rounds: 120 + 40, then 20.
        $lines = $this->fetchRows(
            'SELECT r.number, p.name, u.credits FROM ai_usage u JOIN rounds r ON r.id = u.round_id JOIN ai_providers p ON p.id = u.provider_id
             WHERE r.task_id = ? ORDER BY r.number, u.id',
            [$id],
        );
        self::assertSame(
            [[1, 'Runway', '120.00'], [1, 'Midjourney', '40.00'], [2, 'Runway', '20.00']],
            array_map(static fn(array $line): array => [self::integer($line['number']), self::text($line['name']), self::text($line['credits'])], $lines),
        );
        self::assertSame('180.00', $this->fetchValue('SELECT SUM(u.credits) FROM ai_usage u JOIN rounds r ON r.id = u.round_id WHERE r.task_id = ?', [$id]));

        // Pickup to approval: 4 d 2 h of elapsed time on the seeded clock.
        $pickedUp = new DateTimeImmutable(self::text($events[1]['at']), new DateTimeZone('UTC'));
        $approved = new DateTimeImmutable(self::text($events[7]['at']), new DateTimeZone('UTC'));
        self::assertSame(4 * 86400 + 2 * 3600, $approved->getTimestamp() - $pickedUp->getTimestamp());

        // The card says the same.
        $detail = $this->request('GET', '/api/tasks/' . $id, as: $this->service(UserRepository::class)->findByUsername('manager'));
        self::assertSame(200, $detail->getStatusCode());
        self::assertSame('180.00', self::jsonAt($detail, 'creditsTotal'));
        self::assertSame(2, self::jsonAt($detail, 'roundCount'));
        self::assertSame(4 * 86400 + 2 * 3600, self::jsonAt($detail, 'metrics.cycleSeconds'));
        self::assertCount(2, self::jsonArray($detail, 'rounds'));
    }

    public function test_dates_are_relative_to_now(): void
    {
        $this->sharedSeed();

        $this->assertDatesAreRelativeTo($this->clock->now());
    }

    public function test_the_whole_database_satisfies_the_invariants(): void
    {
        $this->sharedSeed();
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');

        foreach ($this->fetchRows('SELECT id, offer, status, assignee_id FROM tasks') as $task) {
            $id = self::integer($task['id']);
            $label = self::text($task['offer']) . ' (' . self::text($task['status']) . ')';
            $status = self::text($task['status']);

            $open = $this->fetchRows('SELECT designer_id FROM assignments WHERE task_id = ? AND ended_at IS NULL', [$id]);
            $latest = $this->fetchRows('SELECT decision FROM rounds WHERE task_id = ? ORDER BY number DESC LIMIT 1', [$id]);

            if (in_array($status, ['in_progress', 'under_review', 'revisions_required'], true)) {
                self::assertCount(1, $open, $label . ' needs exactly one open assignment');
                self::assertSame($task['assignee_id'], $open[0]['designer_id'], $label . ': assignee_id differs from the open assignment');
            } else {
                self::assertSame([], $open, $label . ' must not have an open assignment');
            }
            if ($status === 'new') {
                self::assertNull($task['assignee_id'], $label);
            }
            if ($status === 'completed') {
                self::assertNotNull($task['assignee_id'], $label);
                self::assertSame('approved', $latest[0]['decision'] ?? null, $label);
            }
            if ($status === 'under_review') {
                self::assertCount(1, $latest, $label);
                self::assertNull($latest[0]['decision'], $label . ': the latest round must wait for review');
            }
            if ($status === 'revisions_required') {
                self::assertSame('revisions_requested', $latest[0]['decision'] ?? null, $label);
            }
            if ($status === 'in_progress') {
                self::assertTrue($latest === [] || $latest[0]['decision'] === 'revisions_requested', $label . ': a task in progress has no round waiting');
            }

            // Rounds number 1..n; at most the latest is undecided.
            $numbers = $this->column('SELECT number FROM rounds WHERE task_id = ? ORDER BY number', [$id]);
            self::assertSame(array_map(strval(...), $numbers === [] ? [] : range(1, count($numbers))), $numbers, $label);
            self::assertLessThanOrEqual(1, self::integer($this->fetchValue('SELECT COUNT(*) FROM rounds WHERE task_id = ? AND decision IS NULL', [$id])), $label);

            // History: starts with the creation, never goes back in time, never into the future.
            $events = $this->fetchRows('SELECT type, at FROM task_events WHERE task_id = ? ORDER BY id', [$id]);
            self::assertSame('task_created', $events[0]['type'], $label);
            $previous = '';
            foreach ($events as $event) {
                $at = self::text($event['at']);
                self::assertGreaterThanOrEqual($previous, $at, $label . ': events out of order');
                self::assertLessThanOrEqual($now, $at, $label . ': an event after now');
                $previous = $at;
            }
            if ($status === 'completed' || $status === 'cancelled') {
                self::assertSame($status === 'completed' ? 'task_approved' : 'task_cancelled', $events[count($events) - 1]['type'], $label);
            }
        }

        // Rounds, decisions and assignments are never later than now either.
        self::assertSame('0', self::text($this->fetchValue('SELECT COUNT(*) FROM rounds WHERE submitted_at > ? OR decided_at > ?', [$now, $now])));
        self::assertSame('0', self::text($this->fetchValue('SELECT COUNT(*) FROM assignments WHERE assigned_at > ? OR ended_at > ?', [$now, $now])));

        // Every file row has its bytes, is a real PNG, and is attached to a task as what it is.
        $files = $this->fetchRows('SELECT f.id, f.name, f.mime, f.size, f.path, a.kind, d.id AS deliverable FROM files f JOIN file_attachments a ON a.file_id = f.id LEFT JOIN deliverables d ON d.file_id = f.id');
        self::assertGreaterThanOrEqual(20, count($files));
        foreach ($files as $file) {
            $path = $this->uploadDir . '/' . self::text($file['path']);
            self::assertFileExists($path);
            self::assertSame('image/png', $file['mime']);
            self::assertSame(self::integer($file['size']), filesize($path));
            self::assertStringEndsWith('.png', self::text($file['name']));
            self::assertSame($file['kind'] === 'deliverable', $file['deliverable'] !== null);
            $image = imagecreatefromstring((string) file_get_contents($path));
            self::assertNotFalse($image, 'not a decodable image: ' . self::text($file['name']));
        }
        self::assertSame($this->countRows('files'), count($files), 'a file nobody owns');
        self::assertGreaterThan(5, count(array_unique(array_map(self::text(...), array_column($files, 'name')))), 'file names vary');
        // Some tasks carry reference links and some reference files.
        self::assertGreaterThan(0, self::integer($this->fetchValue("SELECT COUNT(*) FROM task_references WHERE kind = 'url'")));
        self::assertGreaterThan(0, self::integer($this->fetchValue("SELECT COUNT(*) FROM task_references WHERE kind = 'file'")));
    }

    public function test_dashboard_has_sensible_numbers_for_the_manager(): void
    {
        $this->sharedSeed();
        $manager = $this->service(UserRepository::class)->findByUsername('manager');

        $response = $this->request('GET', '/api/analytics/dashboard', as: $manager);

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame(16, self::jsonAt($response, 'totals.total'));
        self::assertSame(7, self::jsonAt($response, 'totals.active'));
        self::assertSame(2, self::jsonAt($response, 'totals.cancelled'));
        self::assertSame(2, self::jsonAt($response, 'overdue.count'));
        self::assertSame(4, self::jsonAt($response, 'averages.completedCount'));
        foreach (['cycleSeconds', 'queueWaitSeconds', 'reviewSecondsPerRound', 'revisionTurnaroundSeconds'] as $average) {
            $value = self::jsonAt($response, 'averages.' . $average);
            self::assertIsInt($value, $average);
            self::assertGreaterThan(0, $value, $average);
        }
        self::assertGreaterThanOrEqual(4, count(self::jsonArray($response, 'ai.byProvider')));
        self::assertCount(3, self::jsonArray($response, 'ai.byDesigner'));
        self::assertCount(3, self::jsonArray($response, 'workload'));
        self::assertGreaterThan(180.0, (float) self::text(self::jsonAt($response, 'ai.total')));
    }

    public function test_seed_refuses_when_tasks_exist(): void
    {
        $this->sharedSeed();
        $before = $this->fetchRows('SELECT id, status, updated_at FROM tasks ORDER BY id');
        $files = $this->filesOnDisk();

        [$exit, $stdout, $stderr] = $this->execute($this->command(SeedCommand::class));

        self::assertNotSame(0, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('already seeded; use demo:reset', $stderr);
        self::assertSame($before, $this->fetchRows('SELECT id, status, updated_at FROM tasks ORDER BY id'));
        self::assertSame($files, $this->filesOnDisk());
        self::assertSame(16, $this->countRows('tasks'));
    }

    public function test_seed_if_empty_does_nothing_when_tasks_exist(): void
    {
        $this->sharedSeed();
        $before = $this->fetchRows('SELECT id, status, updated_at FROM tasks ORDER BY id');
        $events = $this->countRows('task_events');

        [$exit, , $stderr] = $this->execute($this->command(SeedIfEmptyCommand::class));

        self::assertSame(0, $exit, $stderr);
        self::assertSame($before, $this->fetchRows('SELECT id, status, updated_at FROM tasks ORDER BY id'));
        self::assertSame($events, $this->countRows('task_events'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodePayload(mixed $json): array
    {
        $payload = json_decode(self::text($json), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $named = [];
        foreach ($payload as $key => $value) {
            $named[(string) $key] = $value;
        }

        return $named;
    }
}
