<?php

declare(strict_types=1);

namespace App\Tests\History;

use App\History\FieldAuditLog;
use App\History\TaskEventLog;
use App\History\TaskEventType;
use App\Identity\Role;
use App\Identity\User;
use App\Identity\UserRepository;
use App\Tests\Support\ApiTestCase;
use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * The field-level audit of a brief edit: which field, from what, to what. Each row belongs to the
 * event that records the edit, which supplies who and when.
 */
final class FieldAuditTest extends ApiTestCase
{
    private User $buyer;

    private int $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = $this->service(UserRepository::class)->create('anna', 'Anna', Role::Buyer, 'en');
        $this->task = $this->insertTask($this->buyer);
    }

    public function test_changes_stored_with_old_and_new_values(): void
    {
        $eventId = $this->briefEdited();
        $changes = [
            ['field' => 'offer', 'old' => 'Лото 🎰', 'new' => 'Bingo <b>Лото</b>'],
            ['field' => 'geo', 'old' => ['US', 'DE'], 'new' => ['DE', 'PL', 'WW']],
            ['field' => 'quantity', 'old' => 3, 'new' => 5],
            ['field' => 'description', 'old' => null, 'new' => 'Сделать ярче'],
            ['field' => 'deadline', 'old' => '2026-11-12T10:00:00Z', 'new' => null],
            ['field' => 'urgent', 'old' => false, 'new' => true],
            ['field' => 'references', 'old' => [], 'new' => [['kind' => 'url', 'url' => 'https://example.com/a/b']]],
        ];

        $this->audit()->record($eventId, $changes);

        // The same values in the same shape and the order they were recorded.
        self::assertSame($changes, $this->audit()->forEvent($eventId));
    }

    public function test_values_are_stored_as_json_with_null_as_a_json_null(): void
    {
        $eventId = $this->briefEdited();

        $this->audit()->record($eventId, [
            ['field' => 'description', 'old' => null, 'new' => 'Лото 🎰'],
            ['field' => 'geo', 'old' => ['US'], 'new' => ['DE']],
        ]);

        $statement = $this->database->pdo()->query('SELECT field, old_value, new_value FROM task_field_changes ORDER BY id');
        self::assertNotFalse($statement);
        self::assertSame(
            [
                ['field' => 'description', 'old_value' => 'null', 'new_value' => '"Лото 🎰"'],
                ['field' => 'geo', 'old_value' => '["US"]', 'new_value' => '["DE"]'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function test_changes_are_returned_for_their_own_event_only(): void
    {
        $first = $this->briefEdited();
        $second = $this->briefEdited();
        $untouched = $this->briefEdited();

        $this->audit()->record($first, [['field' => 'offer', 'old' => 'A', 'new' => 'B']]);
        $this->audit()->record($second, [['field' => 'quantity', 'old' => 1, 'new' => 2], ['field' => 'offer', 'old' => 'B', 'new' => 'C']]);

        self::assertSame([['field' => 'offer', 'old' => 'A', 'new' => 'B']], $this->audit()->forEvent($first));
        self::assertSame(
            [['field' => 'quantity', 'old' => 1, 'new' => 2], ['field' => 'offer', 'old' => 'B', 'new' => 'C']],
            $this->audit()->forEvent($second),
        );
        self::assertSame([], $this->audit()->forEvent($untouched));
        self::assertSame([], $this->audit()->forEvent($untouched + 1000));
    }

    public function test_recording_no_changes_stores_nothing(): void
    {
        $this->audit()->record($this->briefEdited(), []);

        self::assertSame(0, $this->countRows('task_field_changes'));
    }

    public function test_a_malformed_change_is_refused_and_none_of_the_batch_is_stored(): void
    {
        $eventId = $this->briefEdited();
        $valid = ['field' => 'offer', 'old' => 'A', 'new' => 'B'];

        $refused = [
            'no new key' => ['field' => 'offer', 'old' => 'A'],
            'no old key' => ['field' => 'offer', 'new' => 'B'],
            'no field' => ['old' => 'A', 'new' => 'B'],
            'an empty field' => ['field' => '', 'old' => 'A', 'new' => 'B'],
            'a field that is not a string' => ['field' => 7, 'old' => 'A', 'new' => 'B'],
            'a field that is too long' => ['field' => str_repeat('f', 101), 'old' => 'A', 'new' => 'B'],
            'a value that is not JSON' => ['field' => 'offer', 'old' => "bad\xFF", 'new' => 'B'],
            'a number that is not JSON' => ['field' => 'offer', 'old' => 1, 'new' => NAN],
        ];

        foreach ($refused as $name => $change) {
            /** @var list<array{field: string, old: mixed, new: mixed}> $batch the malformed shape is the point of the test */
            $batch = [$valid, $change];

            try {
                $this->audit()->record($eventId, $batch);
                self::fail(sprintf('A change with %s must be refused.', $name));
            } catch (InvalidArgumentException) {
                self::assertSame(0, $this->countRows('task_field_changes'), $name);
            }
        }
    }

    public function test_changes_for_an_event_that_does_not_exist_are_refused(): void
    {
        $this->expectException(PDOException::class);

        try {
            $this->audit()->record(987654, [['field' => 'offer', 'old' => 'A', 'new' => 'B']]);
        } finally {
            self::assertSame(0, $this->countRows('task_field_changes'));
        }
    }

    public function test_the_table_has_the_documented_columns(): void
    {
        $pdo = $this->database->pdo();

        $found = $this->columnsOf('task_field_changes');

        self::assertSame(['id', 'event_id', 'field', 'old_value', 'new_value'], array_keys($found));
        self::assertSame(['bigint unsigned', 'NO'], $found['id']);
        self::assertSame(['bigint unsigned', 'NO'], $found['event_id']);
        self::assertSame('NO', $found['field'][1]);
        self::assertSame('NO', $found['old_value'][1]);
        self::assertSame('NO', $found['new_value'][1]);

        // Rows belong to an event and go with it; nothing else removes them.
        $keys = $pdo->query(
            "SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'task_field_changes'",
        );
        self::assertNotFalse($keys);
        self::assertSame([['COLUMN_NAME' => 'event_id', 'REFERENCED_TABLE_NAME' => 'task_events', 'DELETE_RULE' => 'CASCADE']], $keys->fetchAll(PDO::FETCH_ASSOC));

        // The value columns only hold JSON.
        $eventId = $this->briefEdited();
        $this->expectException(PDOException::class);
        $pdo->prepare("INSERT INTO task_field_changes (event_id, field, old_value, new_value) VALUES (?, 'offer', 'not json', '1')")
            ->execute([$eventId]);
    }

    private function audit(): FieldAuditLog
    {
        return $this->service(FieldAuditLog::class);
    }

    /**
     * A brief_edited event of the test's buyer; the field changes of an edit belong to such an event.
     */
    private function briefEdited(): int
    {
        return $this->service(TaskEventLog::class)->record($this->task, TaskEventType::BriefEdited, $this->buyer);
    }
}
