<?php

declare(strict_types=1);

namespace App\Tests\Rounds;

use PDOException;

/**
 * The tables of rounds as the database itself enforces them, whoever writes to them.
 */
final class RoundsSchemaTest extends RoundsTestCase
{
    public function test_the_tables_have_the_documented_columns(): void
    {
        self::assertSame(
            [
                'id' => ['int unsigned', 'NO'],
                'task_id' => ['int unsigned', 'NO'],
                'number' => ['int unsigned', 'NO'],
                'submitted_by' => ['int unsigned', 'NO'],
                'submitted_at' => ['datetime(6)', 'NO'],
                'note' => ['text', 'YES'],
                'decision' => ["enum('revisions_requested','approved')", 'YES'],
                'decision_reason' => ['text', 'YES'],
                'decided_at' => ['datetime(6)', 'YES'],
            ],
            $this->columnsOf('rounds'),
        );
        self::assertSame(
            ['id' => ['int unsigned', 'NO'], 'round_id' => ['int unsigned', 'NO'], 'file_id' => ['int unsigned', 'NO']],
            $this->columnsOf('deliverables'),
        );
    }

    public function test_a_round_number_is_used_once_per_task(): void
    {
        $id = $this->taskOfDave();
        $other = $this->taskOfDave();
        $this->insertRound($id, 1);
        $this->insertRound($other, 1);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Duplicate entry');
        $this->insertRound($id, 1);
    }

    public function test_a_file_is_a_deliverable_once(): void
    {
        $id = $this->taskOfDave();
        $file = $this->uploadFor($this->dave);
        $round = $this->insertRound($id, 1, [$file]);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Duplicate entry');
        $this->database->pdo()->prepare('INSERT INTO deliverables (round_id, file_id) VALUES (?, ?)')->execute([$round, $file]);
    }

    public function test_a_decision_comes_with_its_time(): void
    {
        $id = $this->taskOfDave();

        $this->expectException(PDOException::class);
        $this->database->pdo()->prepare(
            "INSERT INTO rounds (task_id, number, submitted_by, submitted_at, decision) VALUES (?, 1, ?, '2026-01-10 10:00:00', 'approved')",
        )->execute([$id, $this->dave->id]);
    }

    public function test_deleting_a_task_removes_its_rounds_and_deliverables_but_not_its_files(): void
    {
        $id = $this->taskOfDave();
        $file = $this->uploadFor($this->dave);
        $this->insertRound($id, 1, [$file]);

        $this->database->pdo()->exec('DELETE FROM tasks');

        self::assertSame(0, $this->countRows('rounds'));
        self::assertSame(0, $this->countRows('deliverables'));
        self::assertSame(1, $this->countRows('files'));
    }

    public function test_an_account_that_submitted_a_round_cannot_be_deleted(): void
    {
        $id = $this->taskOfDave();
        $this->insertRound($id, 1);

        $this->expectException(PDOException::class);
        $this->database->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$this->dave->id]);
    }
}
