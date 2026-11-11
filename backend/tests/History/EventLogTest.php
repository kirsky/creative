<?php

declare(strict_types=1);

namespace App\Tests\History;

use App\History\FieldAuditLog;
use App\History\TaskEvent;
use App\History\TaskEventLog;
use App\History\TaskEventType;
use App\Identity\Role;
use App\Identity\User;
use App\Identity\UserRepository;
use App\Shared\Clock;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use ReflectionClass;
use ReflectionMethod;

/**
 * The activity history of a task: what happened, who did it, and when, in the order it happened.
 * Append-only, and the source of every time metric, so the order and the UTC timestamps are exact.
 */
final class EventLogTest extends ApiTestCase
{
    private int $task;

    private int $otherTask;

    protected function setUp(): void
    {
        parent::setUp();

        // task_events.task_id is a foreign key: the events below belong to real tasks.
        $owner = $this->createUser('task-owner', Role::Buyer);
        $this->task = $this->insertTask($owner);
        $this->otherTask = $this->insertTask($owner);
    }

    public function test_record_and_read_back_in_order_with_actor_name(): void
    {
        $buyer = $this->account('anna', 'Анна 🎰', Role::Buyer);
        $designer = $this->account('dina', 'Dina', Role::Designer);
        $log = $this->log();

        $this->clock->set(new DateTimeImmutable('2026-11-10T06:00:00Z'));
        $created = $log->record($this->task, TaskEventType::TaskCreated, $buyer);
        $this->clock->advance('PT5M');
        $other = $log->record($this->otherTask, TaskEventType::TaskCreated, $buyer);
        $this->clock->advance('PT5M');
        $pickedUp = $log->record($this->task, TaskEventType::TaskPickedUp, $designer, ['assignmentId' => 9]);
        $this->clock->advance('PT1H');
        $submitted = $log->record($this->task, TaskEventType::RoundSubmitted, $designer, ['round' => 1, 'fileCount' => 3]);

        // Ids grow with every event, whatever task it belongs to.
        self::assertGreaterThan($created, $other);
        self::assertGreaterThan($other, $pickedUp);
        self::assertGreaterThan($pickedUp, $submitted);

        $events = $log->forTask($this->task);

        // Only this task's events, oldest first.
        self::assertSame([$created, $pickedUp, $submitted], array_map(static fn(TaskEvent $e): int => $e->id, $events));
        self::assertSame(
            [TaskEventType::TaskCreated, TaskEventType::TaskPickedUp, TaskEventType::RoundSubmitted],
            array_map(static fn(TaskEvent $e): TaskEventType => $e->type, $events),
        );
        self::assertSame([$this->task, $this->task, $this->task], array_map(static fn(TaskEvent $e): int => $e->taskId, $events));
        self::assertSame([$buyer->id, $designer->id, $designer->id], array_map(static fn(TaskEvent $e): int => $e->actorId, $events));
        // The name is the user's current display name, joined at read time.
        self::assertSame(['Анна 🎰', 'Dina', 'Dina'], array_map(static fn(TaskEvent $e): string => $e->actorName, $events));
        self::assertSame([[], ['assignmentId' => 9], ['round' => 1, 'fileCount' => 3]], array_map(static fn(TaskEvent $e): array => $e->payload, $events));

        self::assertSame([$other], array_map(static fn(TaskEvent $e): int => $e->id, $log->forTask($this->otherTask)));
        self::assertSame([], $log->forTask($this->task + 1000));
    }

    public function test_for_tasks_groups_the_histories_in_order_and_skips_tasks_without_events(): void
    {
        $designer = $this->account('dina', 'Dina', Role::Designer);
        $log = $this->log();
        $silent = $this->insertTask($this->account('bob', 'Bob', Role::Buyer));

        $this->clock->set(new DateTimeImmutable('2026-11-10T06:00:00Z'));
        $a1 = $log->record($this->task, TaskEventType::TaskCreated, $designer);
        $b1 = $log->record($this->otherTask, TaskEventType::TaskCreated, $designer);
        $this->clock->advance('PT1M');
        $a2 = $log->record($this->task, TaskEventType::TaskPickedUp, $designer);

        $byTask = $log->forTasks([$this->task, $this->otherTask, $silent]);

        self::assertSame([$this->task, $this->otherTask], array_keys($byTask));
        self::assertSame([$a1, $a2], array_map(static fn(TaskEvent $event): int => $event->id, $byTask[$this->task]));
        self::assertSame([$b1], array_map(static fn(TaskEvent $event): int => $event->id, $byTask[$this->otherTask]));
        self::assertSame([], $log->forTasks([]));
        self::assertSame([$this->task], array_keys($log->forTasks([$this->task])));
    }

    public function test_the_actor_name_follows_a_renamed_user(): void
    {
        $buyer = $this->account('anna', 'Anna', Role::Buyer);
        $this->log()->record($this->task, TaskEventType::TaskCreated, $buyer);

        $this->database->pdo()->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute(['Анна Новая', $buyer->id]);

        self::assertSame('Анна Новая', $this->log()->forTask($this->task)[0]->actorName);
    }

    public function test_events_at_the_same_instant_keep_insertion_order(): void
    {
        $buyer = $this->account('anna', 'Anna', Role::Buyer);
        $log = $this->log();
        $this->clock->set(new DateTimeImmutable('2026-11-10T06:00:00Z'));

        $types = [
            TaskEventType::TaskCreated,
            TaskEventType::TaskPickedUp,
            TaskEventType::BriefEdited,
            TaskEventType::AiUsageChanged,
            TaskEventType::RoundSubmitted,
            TaskEventType::TaskApproved,
        ];
        $ids = [];
        foreach ($types as $type) {
            $ids[] = $log->record($this->task, $type, $buyer);
        }

        $events = $log->forTask($this->task);
        self::assertSame($ids, array_map(static fn(TaskEvent $e): int => $e->id, $events));
        self::assertSame($types, array_map(static fn(TaskEvent $e): TaskEventType => $e->type, $events));
    }

    public function test_events_are_ordered_by_time_before_id(): void
    {
        $buyer = $this->account('anna', 'Anna', Role::Buyer);
        $log = $this->log();

        // Recorded second, but it happened first (the clock went back, as when a demo scenario is replayed).
        $this->clock->set(new DateTimeImmutable('2026-11-10T06:00:00Z'));
        $later = $log->record($this->task, TaskEventType::TaskPickedUp, $buyer);
        $this->clock->set(new DateTimeImmutable('2026-11-10T05:00:00Z'));
        $earlier = $log->record($this->task, TaskEventType::TaskCreated, $buyer);

        self::assertSame([$earlier, $later], array_map(static fn(TaskEvent $e): int => $e->id, $log->forTask($this->task)));
    }

    public function test_timestamp_comes_from_clock(): void
    {
        $buyer = $this->account('anna', 'Anna', Role::Buyer);
        $this->clock->set(new DateTimeImmutable('2026-11-10T06:00:00Z'));

        $id = $this->log()->record($this->task, TaskEventType::TaskCreated, $buyer);

        $event = $this->log()->forTask($this->task)[0];
        self::assertSame($id, $event->id);
        self::assertSame('2026-11-10T06:00:00+00:00', $event->at->format('c'));
        self::assertSame('UTC', $event->at->getTimezone()->getName());
        self::assertSame('2026-11-10 06:00:00.000000', $this->rawColumn('at', $id));

        // Moving the clock moves the next event, and only that one.
        $this->clock->advance('PT90M');
        $next = $this->log()->record($this->task, TaskEventType::TaskPickedUp, $buyer);
        self::assertSame('2026-11-10 07:30:00.000000', $this->rawColumn('at', $next));
        self::assertSame('2026-11-10 06:00:00.000000', $this->rawColumn('at', $id));
    }

    public function test_timestamp_keeps_microseconds(): void
    {
        $buyer = $this->account('anna', 'Anna', Role::Buyer);
        $this->clock->set(new DateTimeImmutable('2026-11-10T06:00:00.123456Z'));

        $this->log()->record($this->task, TaskEventType::TaskCreated, $buyer);

        $event = $this->log()->forTask($this->task)[0];
        self::assertSame('2026-11-10 06:00:00.123456', $event->at->format('Y-m-d H:i:s.u'));
    }

    public function test_a_clock_in_another_time_zone_is_stored_as_utc(): void
    {
        $buyer = $this->account('anna', 'Anna', Role::Buyer);
        $clock = new class implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-11-10 09:00:00', new DateTimeZone('Europe/Moscow'));
            }
        };

        $id = (new TaskEventLog($this->database, $clock))->record($this->task, TaskEventType::TaskCreated, $buyer);

        self::assertSame('2026-11-10 06:00:00.000000', $this->rawColumn('at', $id));
        self::assertSame('UTC', $this->log()->forTask($this->task)[0]->at->getTimezone()->getName());
    }

    public function test_payload_with_cyrillic_and_emoji_roundtrips(): void
    {
        $buyer = $this->account('anna', 'Anna', Role::Buyer);
        $payload = [
            'round' => 2,
            'reason' => 'Лото 🎰',
            'note' => '<script>alert(1)</script> "quoted" a/b',
            'changes' => [
                ['field' => 'geo', 'old' => ['US', 'DE'], 'new' => ['PL']],
                ['field' => 'offer', 'old' => null, 'new' => 'Лото 🎰'],
            ],
            'flags' => ['urgent' => true, 'ratio' => 0.5],
        ];

        $id = $this->log()->record($this->task, TaskEventType::RevisionsRequested, $buyer, $payload);

        self::assertSame($payload, $this->log()->forTask($this->task)[0]->payload);

        // Stored as readable UTF-8 and plain slashes, not as \uXXXX escapes.
        $raw = $this->rawColumn('payload', $id);
        self::assertStringContainsString('"reason":"Лото 🎰"', $raw);
        self::assertStringContainsString('a/b', $raw);
        self::assertStringNotContainsString('\\u', $raw);
        self::assertStringNotContainsString('\\/', $raw);
    }

    public function test_an_empty_payload_is_stored_as_an_object_and_read_back_as_an_empty_array(): void
    {
        $buyer = $this->account('anna', 'Anna', Role::Buyer);

        $id = $this->log()->record($this->task, TaskEventType::TaskCreated, $buyer);

        self::assertSame('{}', $this->rawColumn('payload', $id));
        self::assertSame([], $this->log()->forTask($this->task)[0]->payload);
    }

    public function test_a_payload_that_is_not_json_is_refused_and_nothing_is_recorded(): void
    {
        $buyer = $this->account('anna', 'Anna', Role::Buyer);
        $log = $this->log();

        $refused = [
            'invalid utf-8' => ['reason' => "bad\xFFtext"],
            'not a number' => ['credits' => NAN],
            'a resource' => ['file' => STDIN],
            'a list instead of an object' => [['round' => 1]],
        ];

        foreach ($refused as $name => $payload) {
            try {
                $log->record($this->task, TaskEventType::RevisionsRequested, $buyer, $payload);
                self::fail(sprintf('A payload with %s must be refused.', $name));
            } catch (InvalidArgumentException) {
                self::assertSame(0, $this->countRows('task_events'), $name);
            }
        }
    }

    public function test_the_actor_must_exist(): void
    {
        $ghost = new User(9999, 'ghost', 'Ghost', Role::Buyer, 'en', null);

        $this->expectException(PDOException::class);

        try {
            $this->log()->record($this->task, TaskEventType::TaskCreated, $ghost);
        } finally {
            self::assertSame(0, $this->countRows('task_events'));
        }
    }

    public function test_the_events_of_a_task_go_with_the_task(): void
    {
        $buyer = $this->account('anna', 'Anna', Role::Buyer);
        $this->log()->record($this->task, TaskEventType::TaskCreated, $buyer);
        $this->log()->record($this->otherTask, TaskEventType::TaskCreated, $buyer);

        $this->database->pdo()->prepare('DELETE FROM tasks WHERE id = ?')->execute([$this->task]);

        self::assertSame([], $this->log()->forTask($this->task));
        self::assertCount(1, $this->log()->forTask($this->otherTask));
    }

    public function test_an_event_needs_an_existing_task(): void
    {
        $buyer = $this->account('anna', 'Anna', Role::Buyer);

        $this->expectException(PDOException::class);

        $this->log()->record(987_654, TaskEventType::TaskCreated, $buyer);
    }

    public function test_an_actor_with_history_cannot_be_deleted(): void
    {
        $buyer = $this->account('anna', 'Anna', Role::Buyer);
        $this->log()->record($this->task, TaskEventType::TaskCreated, $buyer);

        try {
            $this->database->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$buyer->id]);
            self::fail('The history must keep its actors.');
        } catch (PDOException) {
            self::assertSame(1, $this->countRows('task_events'));
        }
    }

    public function test_log_classes_expose_no_update_or_delete_methods(): void
    {
        $expected = [
            TaskEventLog::class => ['__construct', 'forTask', 'forTasks', 'record'],
            FieldAuditLog::class => ['__construct', 'forEvent', 'record'],
        ];

        foreach ($expected as $class => $methods) {
            $public = array_map(
                static fn(ReflectionMethod $method): string => $method->getName(),
                (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
            );
            sort($public);

            self::assertSame($methods, $public, $class);
        }
    }

    public function test_history_code_contains_no_statement_that_changes_or_removes_rows(): void
    {
        $files = glob(__DIR__ . '/../../src/History/*.php');
        self::assertIsArray($files);
        self::assertNotSame([], $files);

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);

            self::assertSame(0, preg_match('/\b(UPDATE|DELETE|TRUNCATE|DROP|REPLACE)\b/', $code), basename($file) . ' changes or removes rows');
        }
    }

    public function test_the_table_has_the_documented_columns(): void
    {
        $pdo = $this->database->pdo();

        $found = $this->columnsOf('task_events');

        self::assertSame(['id', 'task_id', 'type', 'actor_id', 'at', 'payload'], array_keys($found));
        self::assertSame(['bigint unsigned', 'NO'], $found['id']);
        self::assertSame(['int unsigned', 'NO'], $found['task_id']);
        self::assertSame(['int unsigned', 'NO'], $found['actor_id']);
        self::assertSame(['datetime(6)', 'NO'], $found['at']);
        self::assertSame('NO', $found['payload'][1]);

        // The actor is RESTRICT (an account with history cannot be deleted); the task is CASCADE (events go with their task).
        $keys = $pdo->query(
            "SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'task_events'",
        );
        self::assertNotFalse($keys);
        self::assertEqualsCanonicalizing(
            [
                ['COLUMN_NAME' => 'actor_id', 'REFERENCED_TABLE_NAME' => 'users', 'DELETE_RULE' => 'RESTRICT'],
                ['COLUMN_NAME' => 'task_id', 'REFERENCED_TABLE_NAME' => 'tasks', 'DELETE_RULE' => 'CASCADE'],
            ],
            $keys->fetchAll(PDO::FETCH_ASSOC),
        );

        // The payload column only holds JSON.
        $buyer = $this->account('anna', 'Anna', Role::Buyer);
        $this->expectException(PDOException::class);
        $pdo->prepare("INSERT INTO task_events (task_id, type, actor_id, at, payload) VALUES (?, 'task_created', ?, '2026-11-10 06:00:00', 'not json')")
            ->execute([$this->task, $buyer->id]);
    }

    private function log(): TaskEventLog
    {
        return $this->service(TaskEventLog::class);
    }

    private function account(string $username, string $displayName, Role $role): User
    {
        return $this->service(UserRepository::class)->create($username, $displayName, $role, 'en');
    }

    /**
     * A column of a task_events row as the database stores it.
     */
    private function rawColumn(string $column, int $id): string
    {
        $statement = $this->database->pdo()->prepare('SELECT `' . $column . '` FROM task_events WHERE id = ?');
        $statement->execute([$id]);
        $value = $statement->fetchColumn();
        self::assertIsString($value);

        return $value;
    }
}
