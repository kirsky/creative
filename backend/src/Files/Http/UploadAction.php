<?php

declare(strict_types=1);

namespace App\Files\Http;

use App\Files\FileAccess;
use App\Files\FileStore;
use App\Identity\AuthMiddleware;
use App\Shared\Http\ApiError;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /api/files: multipart/form-data with exactly one file, in the field "file". Answers 201 with
 * {id, name, mime, size}. What the file is allowed to be is FileStore's decision.
 */
final class UploadAction
{
    /**
     * @param int|null $postMaxBytes PHP's post_max_size in bytes (0 = unlimited); null reads it from php.ini.
     *     Only tests set it, because the ini setting cannot be changed at run time.
     */
    public function __construct(
        private readonly FileStore $files,
        private readonly FileAccess $access,
        private readonly JsonResponder $json,
        private readonly ?int $postMaxBytes = null,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actor = AuthMiddleware::actor($request);
        if (!$this->access->canUpload($actor)) {
            throw ApiError::forbidden();
        }

        $uploads = $request->getUploadedFiles();
        if ($uploads === []) {
            throw $this->bodyWasDropped($request)
                ? new ApiError(413, 'file_too_large', params: ['maxBytes' => $this->files->maxBytes()])
                : self::fileProblem('required');
        }

        if (!isset($uploads['file'])) {
            throw self::fileProblem('required');
        }

        // One file per request: "file" must be a single upload, and nothing else may come with it.
        $upload = $uploads['file'];
        if (count($uploads) !== 1 || !$upload instanceof UploadedFileInterface) {
            throw self::fileProblem('invalid');
        }

        $file = $this->files->put($actor, $upload);

        return $this->json->respond(
            ['id' => $file->id, 'name' => $file->name, 'mime' => $file->mime, 'size' => $file->size],
            201,
        );
    }

    /**
     * When a request is larger than post_max_size, PHP throws the whole body away without a word:
     * no files, no fields. The request then looks like one that forgot the file, but the truth is
     * that it was too large, and the client should be told so.
     */
    private function bodyWasDropped(ServerRequestInterface $request): bool
    {
        $limit = $this->postMaxBytes ?? self::iniBytes((string) ini_get('post_max_size'));
        $length = $request->getServerParams()['CONTENT_LENGTH'] ?? '';

        return $limit > 0 && is_string($length) && ctype_digit($length) && (int) $length > $limit;
    }

    /**
     * php.ini shorthand ("110M", "512K", "1G", "2097152") in bytes.
     */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private static function fileProblem(string $code): ApiError
    {
        return new ApiError(422, 'validation_failed', [['field' => 'file', 'code' => $code, 'params' => []]]);
    }
}
