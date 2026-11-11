<?php

declare(strict_types=1);

namespace App\Tests\Files;

use App\Files\AttachmentKind;
use App\Files\FileStore;
use App\Files\StoredFile;
use App\Identity\Role;
use App\Identity\User;
use App\Shared\Http\ApiError;
use App\Tests\Support\ApiTestCase;
use PDOException;
use RuntimeException;

/**
 * The storage layer without HTTP: attaching files to tasks, the tables, and wiping the store for a
 * demo reset, which must stay inside the upload directory whatever it finds there.
 */
final class FileStoreTest extends ApiTestCase
{
    private FileStore $store;

    private User $buyer;

    private int $task;

    private int $otherTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = $this->service(FileStore::class);
        $this->buyer = $this->createUser('anna', Role::Buyer);
        // file_attachments.task_id is a foreign key: files are attached to real tasks.
        $this->task = $this->insertTask($this->buyer);
        $this->otherTask = $this->insertTask($this->buyer);
    }

    public function test_put_stores_the_bytes_and_returns_what_was_stored(): void
    {
        $png = Samples::png();

        $file = $this->store->put($this->buyer, $this->uploadedFile($png, 'a/b.png'));

        self::assertSame('b.png', $file->name);
        self::assertSame('image/png', $file->mime);
        self::assertSame(strlen($png), $file->size);
        self::assertSame($this->buyer->id, $file->uploaderId);
        self::assertEquals($file, $this->store->find($file->id));
    }

    public function test_find_returns_null_for_an_unknown_id(): void
    {
        self::assertNull($this->store->find(1));
    }

    public function test_a_failed_insert_removes_the_file_it_had_written(): void
    {
        $nobody = new User(999_999, 'ghost', 'Ghost', Role::Buyer, 'en', null);

        try {
            $this->store->put($nobody, $this->uploadedFile(Samples::png(), 'a.png'));
            self::fail('The uploader does not exist, the insert must fail.');
        } catch (PDOException) {
        }

        self::assertSame(0, $this->countRows('files'));
        // Directories made on the way are harmless; no file may be left.
        self::assertSame([], array_filter($this->listing($this->uploadDir), is_file(...)));
    }

    public function test_attach_links_the_file_to_the_task(): void
    {
        $file = $this->putPng($this->buyer);

        $this->store->attach($file->id, $this->task, AttachmentKind::Reference, $this->buyer);

        self::assertSame(
            [['file_id' => $file->id, 'task_id' => $this->task, 'kind' => 'reference']],
            $this->attachments(),
        );
    }

    public function test_attach_stores_the_kind(): void
    {
        $reference = $this->putPng($this->buyer);
        $deliverable = $this->putPng($this->buyer);

        $this->store->attach($reference->id, $this->task, AttachmentKind::Reference, $this->buyer);
        $this->store->attach($deliverable->id, $this->task, AttachmentKind::Deliverable, $this->buyer);

        self::assertSame(
            [
                ['file_id' => $reference->id, 'task_id' => $this->task, 'kind' => 'reference'],
                ['file_id' => $deliverable->id, 'task_id' => $this->task, 'kind' => 'deliverable'],
            ],
            $this->attachments(),
        );
    }

    public function test_a_file_can_be_attached_only_once(): void
    {
        $file = $this->putPng($this->buyer);
        $this->store->attach($file->id, $this->task, AttachmentKind::Reference, $this->buyer);

        foreach ([[$this->task, AttachmentKind::Reference], [$this->task, AttachmentKind::Deliverable], [$this->otherTask, AttachmentKind::Reference]] as [$task, $kind]) {
            $this->assertInvalidAttachment(fn() => $this->store->attach($file->id, $task, $kind, $this->buyer));
        }

        self::assertCount(1, $this->attachments());
    }

    public function test_attach_refuses_a_file_that_belongs_to_somebody_else(): void
    {
        $file = $this->putPng($this->createUser('dave', Role::Designer));

        $this->assertInvalidAttachment(fn() => $this->store->attach($file->id, $this->task, AttachmentKind::Reference, $this->buyer));

        self::assertSame([], $this->attachments());
    }

    public function test_attach_refuses_a_file_that_does_not_exist(): void
    {
        $this->assertInvalidAttachment(fn() => $this->store->attach(12345, $this->task, AttachmentKind::Reference, $this->buyer));
    }

    public function test_attaching_does_not_touch_the_stored_bytes(): void
    {
        $png = Samples::png();
        $file = $this->store->put($this->buyer, $this->uploadedFile($png, 'a.png'));
        $before = $this->listing($this->uploadDir);

        $this->store->attach($file->id, $this->task, AttachmentKind::Reference, $this->buyer);

        self::assertSame($before, $this->listing($this->uploadDir));
    }

    public function test_the_tables_have_the_documented_shape(): void
    {
        self::assertSame(
            [
                'id' => ['int unsigned', 'NO'],
                'uploader_id' => ['int unsigned', 'NO'],
                'name' => ['varchar(255)', 'NO'],
                'mime' => ['varchar(100)', 'NO'],
                'size' => ['bigint unsigned', 'NO'],
                'path' => ['varchar(64)', 'NO'],
                'created_at' => ['datetime', 'NO'],
            ],
            $this->columnsOf('files'),
        );
        self::assertSame(
            [
                'file_id' => ['int unsigned', 'NO'],
                'task_id' => ['int unsigned', 'NO'],
                'kind' => ["enum('reference','deliverable')", 'NO'],
            ],
            $this->columnsOf('file_attachments'),
        );

        // A file is attached once (file_id is unique); attachments go with their file and with their task.
        self::assertSame(['file_id'], $this->uniqueColumns('file_attachments'));
        self::assertSame(
            [
                'file_attachments.file_id -> files.id CASCADE',
                'file_attachments.task_id -> tasks.id CASCADE',
                'files.uploader_id -> users.id RESTRICT',
            ],
            $this->foreignKeys(),
        );
    }

    public function test_the_database_refuses_a_second_attachment_of_the_same_file(): void
    {
        $file = $this->putPng($this->buyer);
        $insert = $this->database->pdo()->prepare("INSERT INTO file_attachments (file_id, task_id, kind) VALUES (?, ?, 'reference')");
        $insert->execute([$file->id, $this->task]);

        $this->expectException(PDOException::class);

        $insert->execute([$file->id, $this->task]);
    }

    public function test_deleting_a_task_removes_its_attachments_but_not_the_files(): void
    {
        $file = $this->putPng($this->buyer);
        $this->store->attach($file->id, $this->task, AttachmentKind::Reference, $this->buyer);

        $this->database->pdo()->prepare('DELETE FROM tasks WHERE id = ?')->execute([$this->task]);

        self::assertSame(0, $this->countRows('file_attachments'));
        self::assertSame(1, $this->countRows('files'));
    }

    public function test_attaching_to_a_task_that_does_not_exist_is_refused_by_the_database(): void
    {
        $file = $this->putPng($this->buyer);

        $this->expectException(PDOException::class);

        $this->store->attach($file->id, 987_654, AttachmentKind::Reference, $this->buyer);
    }

    public function test_deleting_a_file_row_removes_its_attachment(): void
    {
        $file = $this->putPng($this->buyer);
        $this->store->attach($file->id, $this->task, AttachmentKind::Reference, $this->buyer);

        $this->database->pdo()->exec('DELETE FROM files');

        self::assertSame(0, $this->countRows('file_attachments'));
    }

    public function test_delete_all_removes_every_row_and_every_stored_file_but_not_the_root(): void
    {
        $first = $this->putPng($this->buyer);
        $this->putPng($this->buyer);
        $this->clock->advance('P1M');
        $this->putPng($this->createUser('dave', Role::Designer));
        $this->store->attach($first->id, $this->task, AttachmentKind::Reference, $this->buyer);
        self::assertCount(3, array_filter($this->listing($this->uploadDir), is_file(...)));
        // Strays that no row knows: a hidden file and a deep directory.
        file_put_contents($this->uploadDir . '/.stray', 'x');
        mkdir($this->uploadDir . '/2026/deep/er', 0750, true);
        file_put_contents($this->uploadDir . '/2026/deep/er/file', 'x');
        $inode = fileinode($this->uploadDir);
        $mode = fileperms($this->uploadDir);

        $this->store->deleteAll();

        self::assertSame(0, $this->countRows('files'));
        self::assertSame(0, $this->countRows('file_attachments'));
        self::assertSame([], $this->listing($this->uploadDir));
        clearstatcache();
        self::assertDirectoryExists($this->uploadDir);
        self::assertSame($inode, fileinode($this->uploadDir));
        self::assertSame($mode, fileperms($this->uploadDir));
    }

    public function test_delete_all_never_touches_anything_outside_the_upload_directory(): void
    {
        $this->putPng($this->buyer);
        $outside = $this->workDir . '/outside';
        mkdir($outside . '/sub', 0700, true);
        file_put_contents($outside . '/sentinel.txt', 'keep me');
        file_put_contents($outside . '/sub/inner.txt', 'keep me too');
        file_put_contents($this->workDir . '/sibling.txt', 'keep me as well');
        // A sibling whose name starts like the upload directory.
        mkdir($this->workDir . '/uploads-other');
        file_put_contents($this->workDir . '/uploads-other/keep.txt', 'keep');

        // Links inside the store that point out of it, and one that points back at the store itself.
        symlink($outside, $this->uploadDir . '/link-to-directory');
        symlink($outside . '/sentinel.txt', $this->uploadDir . '/link-to-file');
        symlink($this->uploadDir, $this->uploadDir . '/link-to-self');
        symlink($this->workDir . '/missing', $this->uploadDir . '/dangling');
        mkdir($this->uploadDir . '/2026/02', 0750, true);
        symlink($outside, $this->uploadDir . '/2026/02/nested-link');
        symlink('../../../outside', $this->uploadDir . '/relative-link');

        $this->store->deleteAll();

        self::assertSame([], $this->listing($this->uploadDir));
        self::assertSame('keep me', file_get_contents($outside . '/sentinel.txt'));
        self::assertSame('keep me too', file_get_contents($outside . '/sub/inner.txt'));
        self::assertSame('keep me as well', file_get_contents($this->workDir . '/sibling.txt'));
        self::assertSame('keep', file_get_contents($this->workDir . '/uploads-other/keep.txt'));
    }

    public function test_delete_all_on_an_empty_or_missing_store_is_fine(): void
    {
        $this->store->deleteAll();
        self::assertSame([], $this->listing($this->uploadDir));

        self::removeTree($this->uploadDir);
        $this->store->deleteAll();
        self::assertSame(0, $this->countRows('files'));
    }

    public function test_delete_all_refuses_an_upload_directory_it_cannot_trust(): void
    {
        $file = $this->putPng($this->buyer);

        foreach (['', 'relative/uploads', 'uploads'] as $directory) {
            $this->bootApp($this->config->with(['UPLOAD_DIR' => $directory]));

            try {
                $this->service(FileStore::class)->deleteAll();
                self::fail('Expected a refusal for UPLOAD_DIR=' . $directory);
            } catch (RuntimeException) {
            }
        }

        self::assertSame(1, $this->countRows('files'));
        self::assertCount(1, array_filter($this->listing($this->uploadDir), is_file(...)));
        self::assertNotNull($this->store->find($file->id));
    }

    private function putPng(User $uploader): StoredFile
    {
        return $this->store->put($uploader, $this->uploadedFile(Samples::png(), 'a.png'));
    }

    private function assertInvalidAttachment(callable $attach): void
    {
        try {
            $attach();
            self::fail('Expected a 422 validation_failed.');
        } catch (ApiError $error) {
            self::assertSame(422, $error->status);
            self::assertSame('validation_failed', $error->code);
            self::assertSame([['field' => 'files', 'code' => 'invalid_choice', 'params' => []]], $error->fields);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attachments(): array
    {
        return $this->fetchRows('SELECT file_id, task_id, kind FROM file_attachments ORDER BY file_id');
    }

    /**
     * Every path under $directory (the directory itself excluded), links not followed, sorted.
     *
     * @return list<string>
     */
    private function listing(string $directory): array
    {
        $paths = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $paths[] = $directory . '/' . $entry;
            if (is_dir($directory . '/' . $entry) && !is_link($directory . '/' . $entry)) {
                $paths = [...$paths, ...$this->listing($directory . '/' . $entry)];
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * @return list<string> the columns of the (single) unique index of a table
     */
    private function uniqueColumns(string $table): array
    {
        $rows = $this->fetchRows(
            'SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table],
        );

        $indexes = [];
        foreach ($rows as $row) {
            $indexes[self::text($row['INDEX_NAME'])][] = self::text($row['COLUMN_NAME']);
        }
        self::assertCount(1, $indexes, 'Expected exactly one unique index on ' . $table);

        return array_values($indexes)[0];
    }

    /**
     * @return list<string> "table.column -> table.column DELETE_RULE" of every foreign key of files and file_attachments, sorted
     */
    private function foreignKeys(): array
    {
        $rows = $this->fetchRows(
            "SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME IN ('files', 'file_attachments') AND k.REFERENCED_TABLE_NAME IS NOT NULL",
        );

        $keys = array_map(
            static fn(array $row): string => sprintf(
                '%s.%s -> %s.%s %s',
                self::text($row['TABLE_NAME']),
                self::text($row['COLUMN_NAME']),
                self::text($row['REFERENCED_TABLE_NAME']),
                self::text($row['REFERENCED_COLUMN_NAME']),
                self::text($row['DELETE_RULE']),
            ),
            $rows,
        );
        sort($keys);

        return $keys;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : self::fail('Expected a string column.');
    }
}
