<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tasks, their geo and their references, and the foreign keys the earlier migrations left open.
 *
 * creative_type and priority are codes validated in PHP (App\Tasks\CreativeType, App\Tasks\Priority),
 * so a new one needs no migration; status is the TaskStatus value. deadline_at, created_at and
 * updated_at are UTC. offer is compared case-insensitively (utf8mb4_unicode_ci, Cyrillic included).
 * created_by and assignee_id are RESTRICT: an account that has tasks cannot be deleted.
 *
 * task_geo holds one row per code (an ISO 3166-1 alpha-2 country or a region such as WW). A
 * reference is a url or a file: a url is stored in task_references.url, a file in file_id (the file
 * is also in file_attachments, which says which task it belongs to and is what file access reads).
 *
 * task_events.task_id and file_attachments.task_id were plain indexed columns while this table did
 * not exist; they now cascade, so removing a task (the demo reset) removes its history and attachments.
 */
final class CreateTasks extends AbstractMigration
{
    // Phinx 0.16 makes columns nullable unless told otherwise, so every column says 'null'.
    public function change(): void
    {
        $this->table('tasks', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('offer', 'string', ['limit' => 100, 'encoding' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'null' => false])
            ->addColumn('creative_type', 'string', ['limit' => 30, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false])
            ->addColumn('quantity', 'smallinteger', ['signed' => false, 'null' => false])
            // TEXT holds 65535 bytes: 10000 characters at 4 bytes each.
            ->addColumn('description', 'text', ['encoding' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'null' => false])
            ->addColumn('priority', 'string', ['limit' => 10, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false])
            ->addColumn('deadline_at', 'datetime', ['null' => false])
            ->addColumn('created_by', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false])
            ->addColumn('assignee_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            // The board: by status, in deadline order; and the "mine" views.
            ->addIndex(['status', 'deadline_at'])
            ->addIndex(['created_by', 'deadline_at'])
            ->addIndex(['assignee_id', 'deadline_at'])
            ->addForeignKey('created_by', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT', 'constraint' => 'tasks_created_by_fk'])
            ->addForeignKey('assignee_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT', 'constraint' => 'tasks_assignee_id_fk'])
            ->create();

        $this->table('task_geo', ['id' => false, 'primary_key' => ['task_id', 'code']])
            ->addColumn('task_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('code', 'string', ['limit' => 5, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false])
            ->addForeignKey('task_id', 'tasks', 'id', ['delete' => 'CASCADE', 'update' => 'RESTRICT', 'constraint' => 'task_geo_task_id_fk'])
            ->create();

        $this->table('task_references', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('task_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('kind', 'enum', ['values' => ['url', 'file'], 'null' => false])
            ->addColumn('url', 'string', ['limit' => 2000, 'encoding' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'null' => true])
            ->addColumn('file_id', 'integer', ['signed' => false, 'null' => true])
            ->addIndex(['task_id', 'id'])
            ->addIndex(['file_id'])
            ->addForeignKey('task_id', 'tasks', 'id', ['delete' => 'CASCADE', 'update' => 'RESTRICT', 'constraint' => 'task_references_task_id_fk'])
            ->addForeignKey('file_id', 'files', 'id', ['delete' => 'CASCADE', 'update' => 'RESTRICT', 'constraint' => 'task_references_file_id_fk'])
            ->create();

        $this->table('task_events')
            ->addForeignKey('task_id', 'tasks', 'id', ['delete' => 'CASCADE', 'update' => 'RESTRICT', 'constraint' => 'task_events_task_id_fk'])
            ->update();

        $this->table('file_attachments')
            ->addForeignKey('task_id', 'tasks', 'id', ['delete' => 'CASCADE', 'update' => 'RESTRICT', 'constraint' => 'file_attachments_task_id_fk'])
            ->update();
    }
}
