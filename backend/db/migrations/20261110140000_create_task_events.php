<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The activity history of tasks (task_events) and the field-level audit of brief edits
 * (task_field_changes). Both are append-only: the application only ever inserts and reads.
 * All timestamps are UTC.
 *
 * task_events.task_id is a plain indexed column for now: the tasks table does not exist yet. The
 * migration that creates tasks adds FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE.
 * actor_id is a real foreign key; RESTRICT keeps an account that has history from being deleted.
 *
 * `at` has microsecond precision, so events recorded within the same second keep their true order;
 * reads order by (at, id). `type` is the TaskEventType value, kept as a string so that a new event
 * type needs no migration. `payload` is a JSON object (never a list) holding structured data, not
 * display text.
 *
 * task_field_changes holds one row per changed field of a brief_edited event. old_value and
 * new_value are JSON values (a string, a number, a list of country codes, or the JSON null), never
 * SQL NULL, so "was empty" and "no value stored" cannot be confused.
 */
final class CreateTaskEvents extends AbstractMigration
{
    // Phinx 0.16 makes columns nullable unless told otherwise, so every column says 'null'.
    public function change(): void
    {
        $this->table('task_events', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('task_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('type', 'string', ['limit' => 50, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false])
            ->addColumn('actor_id', 'integer', ['signed' => false, 'null' => false])
            // 'limit' is the fractional-seconds precision: DATETIME(6).
            ->addColumn('at', 'datetime', ['limit' => 6, 'null' => false])
            ->addColumn('payload', 'json', ['null' => false])
            // The history of one task, in order.
            ->addIndex(['task_id', 'at', 'id'])
            ->addIndex(['actor_id'])
            ->addForeignKey('actor_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT', 'constraint' => 'task_events_actor_id_fk'])
            ->create();

        $this->table('task_field_changes', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('event_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('field', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('old_value', 'json', ['null' => false])
            ->addColumn('new_value', 'json', ['null' => false])
            // The changes of one event, in the order they were recorded.
            ->addIndex(['event_id', 'id'])
            ->addForeignKey('event_id', 'task_events', 'id', ['delete' => 'CASCADE', 'update' => 'RESTRICT', 'constraint' => 'task_field_changes_event_id_fk'])
            ->create();
    }
}
