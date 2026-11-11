<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Who worked on a task and when: one row per assignment, kept for good (release, completion and
 * cancellation close a row, they never delete it). Timestamps are UTC with microseconds, so that
 * assignments within one second keep their true order; reads order by id.
 *
 * ended_how says how a closed assignment ended: released, completed or cancelled. ended_at and
 * ended_how are both NULL while the assignment is open.
 *
 * At most one assignment of a task is open at a time, enforced by the database and not only by the
 * code: open_task_id is task_id while ended_at IS NULL and NULL afterwards, and it is UNIQUE (NULLs
 * do not collide), so a second open row for the same task is refused whoever inserts it.
 *
 * designer_id is RESTRICT: an account that has worked on a task cannot be deleted. Removing a task
 * (the demo reset) removes its assignments.
 */
final class CreateAssignments extends AbstractMigration
{
    public function up(): void
    {
        // Phinx 0.16 has no generated columns, so the table is written out.
        $this->execute(
            "CREATE TABLE assignments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                task_id INT UNSIGNED NOT NULL,
                designer_id INT UNSIGNED NOT NULL,
                assigned_at DATETIME(6) NOT NULL,
                ended_at DATETIME(6) NULL,
                ended_how ENUM('released', 'completed', 'cancelled') NULL,
                open_task_id INT UNSIGNED AS (IF(ended_at IS NULL, task_id, NULL)) PERSISTENT,
                PRIMARY KEY (id),
                UNIQUE KEY assignments_one_open_per_task (open_task_id),
                KEY assignments_task_id_idx (task_id, id),
                KEY assignments_designer_id_idx (designer_id),
                CONSTRAINT assignments_task_id_fk FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT assignments_designer_id_fk FOREIGN KEY (designer_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT assignments_ended_together CHECK ((ended_at IS NULL) = (ended_how IS NULL))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE assignments');
    }
}
