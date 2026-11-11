<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Uploaded files and the link between a file and the task it belongs to.
 *
 * files holds what the client may be told about a file (name, detected type, size) and where the
 * bytes are, relative to UPLOAD_DIR: <yyyy>/<mm>/<32 hex digits>. The client's file name is only a
 * label in `name`; it is never part of a path. `mime` is what finfo found in the bytes.
 * uploader_id is RESTRICT: an account that uploaded files cannot be deleted.
 *
 * file_attachments says which task a file belongs to and in which role. file_id is the primary key,
 * so a file is attached exactly once. task_id is a plain indexed column here, because the tasks
 * table did not exist yet: the CreateTasks migration adds
 * FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE. Deleting a file row removes its
 * attachment. All timestamps are UTC.
 */
final class CreateFiles extends AbstractMigration
{
    // Phinx 0.16 makes columns nullable unless told otherwise, so every column says 'null'.
    public function change(): void
    {
        $this->table('files', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('uploader_id', 'integer', ['signed' => false, 'null' => false])
            // utf8mb4 keeps Cyrillic and emoji; 255 characters, not bytes.
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('mime', 'string', ['limit' => 100, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false])
            ->addColumn('size', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('path', 'string', ['limit' => 64, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['path'], ['unique' => true])
            ->addIndex(['uploader_id'])
            ->addForeignKey('uploader_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT', 'constraint' => 'files_uploader_id_fk'])
            ->create();

        $this->table('file_attachments', ['id' => false, 'primary_key' => 'file_id'])
            ->addColumn('file_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('task_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('kind', 'enum', ['values' => ['reference', 'deliverable'], 'null' => false])
            // The files of one task.
            ->addIndex(['task_id', 'kind'])
            ->addForeignKey('file_id', 'files', 'id', ['delete' => 'CASCADE', 'update' => 'RESTRICT', 'constraint' => 'file_attachments_file_id_fk'])
            ->create();
    }
}
