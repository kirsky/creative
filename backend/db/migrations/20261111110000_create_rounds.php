<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Review rounds and their deliverables.
 *
 * A round is one submission of a task by its designer: number counts from 1 per task and
 * UNIQUE (task_id, number) makes a repeated number impossible, whoever inserts it. submitted_at is
 * UTC with microseconds. The decision columns stay NULL until the buyer decides (a later task writes
 * them): decision is revisions_requested or approved, decided_at says when, decision_reason is the
 * reason or note that came with it. decision and decided_at are both NULL or both set.
 *
 * deliverables are the files of a round. file_id is UNIQUE: a file is a deliverable once, in one
 * round (the file is also attached to the task in file_attachments). Like an attachment, a
 * deliverable goes with its file row, and a round goes with its task (the demo reset); submitted_by
 * is RESTRICT, so an account that submitted a round cannot be deleted.
 */
final class CreateRounds extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE rounds (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                task_id INT UNSIGNED NOT NULL,
                number INT UNSIGNED NOT NULL,
                submitted_by INT UNSIGNED NOT NULL,
                submitted_at DATETIME(6) NOT NULL,
                note TEXT NULL,
                decision ENUM('revisions_requested', 'approved') NULL,
                decision_reason TEXT NULL,
                decided_at DATETIME(6) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY rounds_task_number_uq (task_id, number),
                KEY rounds_submitted_by_idx (submitted_by),
                CONSTRAINT rounds_task_id_fk FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT rounds_submitted_by_fk FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT rounds_decided_together CHECK ((decision IS NULL) = (decided_at IS NULL))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $this->execute(
            'CREATE TABLE deliverables (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                round_id INT UNSIGNED NOT NULL,
                file_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY deliverables_file_id_uq (file_id),
                KEY deliverables_round_id_idx (round_id, id),
                CONSTRAINT deliverables_round_id_fk FOREIGN KEY (round_id) REFERENCES rounds (id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT deliverables_file_id_fk FOREIGN KEY (file_id) REFERENCES files (id) ON DELETE CASCADE ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE deliverables');
        $this->execute('DROP TABLE rounds');
    }
}
