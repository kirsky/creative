<?php

declare(strict_types=1);

namespace App\Files;

use App\Identity\User;
use App\Shared\Clock;
use App\Shared\Config;
use App\Shared\Db\Database;
use App\Shared\Http\ApiError;
use DateTimeZone;
use finfo;
use PDO;
use PDOException;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * Where uploaded files live: bytes under UPLOAD_DIR, a row in `files` for each.
 *
 * Layout: UPLOAD_DIR/<yyyy>/<mm>/<32 random hex digits>. Directories are 0750, files 0640. Nothing
 * the client sent is ever part of a path; the client's file name is kept as a label in the row.
 *
 * What is accepted is decided from the bytes that were stored, with finfo, and never from the
 * client's file name or media type: PNG, JPEG, GIF, WebP, any video/* and audio/*, PDF, ZIP (which
 * also covers zipped design containers) and Photoshop. Everything else, SVG and HTML included, is
 * refused, because those can carry script.
 *
 * An upload is written to a temporary file in UPLOAD_DIR, checked, and only then renamed into its
 * month directory, so a refused or interrupted upload leaves nothing at a path a row points to.
 */
final class FileStore
{
    /** Types accepted besides video/* and audio/*. */
    private const array ALLOWED_TYPES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'application/pdf',
        'application/zip',
        'image/vnd.adobe.photoshop',
    ];

    private const string ALLOWED_FAMILIES = '~^(video|audio)/[a-z0-9][a-z0-9.+-]{0,80}$~';

    /** The shape of files.path, checked again before a path is used. */
    private const string PATH_PATTERN = '~^[0-9]{4}/[0-9]{2}/[0-9a-f]{32}$~';

    private const int DIRECTORY_MODE = 0750;

    private const int FILE_MODE = 0640;

    private const int CHUNK = 65536;

    public function __construct(
        private readonly Config $config,
        private readonly Database $database,
        private readonly Clock $clock,
    ) {}

    /**
     * Largest file accepted, in bytes (MAX_UPLOAD_BYTES).
     */
    public function maxBytes(): int
    {
        $value = $this->config->get('MAX_UPLOAD_BYTES');
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException('MAX_UPLOAD_BYTES must be a positive whole number of bytes.');
        }

        return (int) $value;
    }

    /**
     * Validates and stores an upload. Nothing is left behind unless this returns.
     *
     * @throws ApiError 413 file_too_large; 422 file_empty, file_type_not_allowed, or validation_failed
     *     (field "file") for an upload that did not arrive
     */
    public function put(User $uploader, UploadedFileInterface $upload): StoredFile
    {
        $max = $this->maxBytes();
        $this->requireUploadArrived($upload->getError(), $max);

        $size = $upload->getSize();
        if ($size !== null && $size > $max) {
            throw self::tooLarge($max);
        }

        $root = $this->root();
        $this->ensureDirectory($root);
        $temporary = $root . '/.upload-' . bin2hex(random_bytes(16));
        $final = null;

        try {
            $written = $this->write($upload, $temporary, $max);
            if ($written === 0) {
                throw new ApiError(422, 'file_empty');
            }

            $mime = $this->detectType($temporary);
            if (!self::isAllowed($mime)) {
                throw new ApiError(422, 'file_type_not_allowed');
            }

            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
            $relative = $now->format('Y/m') . '/' . bin2hex(random_bytes(16));
            $final = $root . '/' . $relative;
            $this->ensureDirectory(dirname($final));
            if (!rename($temporary, $final)) {
                throw new RuntimeException('Cannot move the upload into its place.');
            }

            $name = ClientFileName::sanitise($upload->getClientFilename());
            $pdo = $this->database->pdo();
            $pdo->prepare('INSERT INTO files (uploader_id, name, mime, size, path, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$uploader->id, $name, $mime, $written, $relative, $now->format('Y-m-d H:i:s')]);

            return new StoredFile((int) $pdo->lastInsertId(), $name, $mime, $written, $uploader->id);
        } catch (Throwable $failure) {
            foreach ([$temporary, $final] as $leftover) {
                if ($leftover !== null && (is_file($leftover) || is_link($leftover))) {
                    unlink($leftover);
                }
            }

            throw $failure;
        }
    }

    public function find(int $id): ?StoredFile
    {
        $statement = $this->database->pdo()->prepare('SELECT id, name, mime, size, uploader_id FROM files WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        if (!is_numeric($row['id'] ?? null) || !is_string($row['name'] ?? null) || !is_string($row['mime'] ?? null)
            || !is_numeric($row['size'] ?? null) || !is_numeric($row['uploader_id'] ?? null)) {
            throw new RuntimeException('Unexpected shape of a files row.');
        }

        return new StoredFile((int) $row['id'], $row['name'], $row['mime'], (int) $row['size'], (int) $row['uploader_id']);
    }

    /**
     * Opens the bytes of a file for reading.
     *
     * @return resource|null null when the bytes are not there: the file was removed, the stored path
     *     is not one this class writes, or it is not a plain file (a link is never followed)
     */
    public function open(StoredFile $file)
    {
        $statement = $this->database->pdo()->prepare('SELECT path FROM files WHERE id = ?');
        $statement->execute([$file->id]);
        $relative = $statement->fetchColumn();

        if (!is_string($relative) || preg_match(self::PATH_PATTERN, $relative) !== 1) {
            return null;
        }

        $path = $this->root() . '/' . $relative;
        if (is_link($path) || !is_file($path)) {
            return null;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        $stat = fstat($handle);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * Attaches a file to a task. The file must be the actor's own and not attached to anything yet.
     *
     * @throws ApiError 422 validation_failed on field "files" when it is not (an unknown file, somebody
     *     else's file and an attached file are told apart from nothing: the client learns no more than "invalid")
     */
    public function attach(int $fileId, int $taskId, AttachmentKind $kind, User $actor): void
    {
        try {
            $statement = $this->database->pdo()->prepare(
                'INSERT INTO file_attachments (file_id, task_id, kind) SELECT id, ?, ? FROM files WHERE id = ? AND uploader_id = ?',
            );
            $statement->execute([$taskId, $kind->value, $fileId, $actor->id]);
        } catch (PDOException $e) {
            // 1062: duplicate key; file_id is the primary key, so the file is attached already.
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw self::invalidAttachment();
            }

            throw $e;
        }

        if ($statement->rowCount() !== 1) {
            throw self::invalidAttachment();
        }
    }

    /**
     * Deletes every file row and everything under UPLOAD_DIR (the demo reset). UPLOAD_DIR itself stays:
     * it is a volume mount. Links are removed and never followed, so nothing outside UPLOAD_DIR can be
     * reached; strays that no row knows about go too.
     *
     * @throws RuntimeException when UPLOAD_DIR is not an absolute path (nothing is deleted then), or
     *     when something under it could not be removed (everything else was)
     */
    public function deleteAll(): void
    {
        $root = $this->root();
        $real = realpath($root);
        if ($real === '/') {
            throw new RuntimeException('Refusing to empty the file system root.');
        }

        $this->database->pdo()->exec('DELETE FROM files');

        if ($real === false || !is_dir($real)) {
            return;
        }

        $failed = self::removeContents($real);
        if ($failed !== []) {
            throw new RuntimeException(sprintf('Could not remove %d path(s) under %s, for example %s.', count($failed), $root, $failed[0]));
        }
    }

    /**
     * Removes everything inside $directory, not the directory itself.
     *
     * @return list<string> the paths that could not be removed
     */
    private static function removeContents(string $directory): array
    {
        $failed = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            // is_link() comes first: a link to a directory is also a directory, and must only be unlinked.
            if (is_link($path) || !is_dir($path)) {
                if (!@unlink($path)) {
                    $failed[] = $path;
                }

                continue;
            }

            $failed = [...$failed, ...self::removeContents($path)];
            if (!@rmdir($path)) {
                $failed[] = $path;
            }
        }

        return $failed;
    }

    /**
     * @throws ApiError for the PHP upload errors that are the client's doing
     */
    private function requireUploadArrived(int $error, int $max): void
    {
        match ($error) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw self::tooLarge($max),
            UPLOAD_ERR_NO_FILE => throw self::fileProblem('required'),
            UPLOAD_ERR_PARTIAL => throw self::fileProblem('invalid'),
            // No temporary directory, cannot write, an extension stopped it: the server's fault.
            default => throw new RuntimeException(sprintf('PHP could not receive the upload (error %d).', $error)),
        };
    }

    /**
     * Copies the upload to $path (exclusive create) and returns the number of bytes written. The
     * count of bytes actually read is the truth: the size the client or PHP reported is not relied on.
     *
     * @throws ApiError 413 when more than $max bytes arrive
     */
    private function write(UploadedFileInterface $upload, string $path, int $max): int
    {
        $source = $upload->getStream();

        // The file is born 0640 (the umask only removes bits), so it is never readable by others, not even briefly.
        $umask = umask(0137);
        try {
            $target = fopen($path, 'xb');
        } finally {
            umask($umask);
        }

        if ($target === false) {
            throw new RuntimeException('Cannot create a file in the upload directory.');
        }

        try {
            // The umask of the process may have taken more away than that.
            chmod($path, self::FILE_MODE);

            $written = 0;
            while (($chunk = $source->read(self::CHUNK)) !== '') {
                $written += strlen($chunk);
                if ($written > $max) {
                    throw self::tooLarge($max);
                }

                if (fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Cannot write to the upload directory (disk full?).');
                }
            }
        } finally {
            fclose($target);
        }

        return $written;
    }

    private function detectType(string $path): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) ? strtolower($mime) : throw new RuntimeException('finfo cannot read the stored upload.');
    }

    private static function isAllowed(string $mime): bool
    {
        return in_array($mime, self::ALLOWED_TYPES, true) || preg_match(self::ALLOWED_FAMILIES, $mime) === 1;
    }

    /**
     * UPLOAD_DIR, which must be an absolute path.
     */
    private function root(): string
    {
        $root = rtrim($this->config->get('UPLOAD_DIR'), '/');

        if ($root === '' || !str_starts_with($root, '/') || str_contains($root, "\0")) {
            throw new RuntimeException('UPLOAD_DIR must be an absolute path.');
        }

        return $root;
    }

    /**
     * Creates $directory and any missing parent, mode 0750 whatever the umask. Existing directories are left alone.
     */
    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        $this->ensureDirectory(dirname($directory));

        // Another request may have created it in the meantime.
        if (!@mkdir($directory, self::DIRECTORY_MODE) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create the directory %s.', $directory));
        }

        chmod($directory, self::DIRECTORY_MODE);
    }

    private static function tooLarge(int $max): ApiError
    {
        return new ApiError(413, 'file_too_large', params: ['maxBytes' => $max]);
    }

    private static function fileProblem(string $code): ApiError
    {
        return new ApiError(422, 'validation_failed', [['field' => 'file', 'code' => $code, 'params' => []]]);
    }

    private static function invalidAttachment(): ApiError
    {
        return new ApiError(422, 'validation_failed', [['field' => 'files', 'code' => 'invalid_choice', 'params' => []]]);
    }
}
