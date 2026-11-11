<?php

declare(strict_types=1);

namespace App\Files\Http;

use App\Files\ContentDisposition;
use App\Files\FileAccess;
use App\Files\FileStore;
use App\Identity\AuthMiddleware;
use App\Shared\Http\ApiError;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * GET /api/files/{id}: the bytes of a file, to a user who may read it; 404 not_found for everybody
 * else, and for a file that does not exist, so the answer tells nothing about which ids are taken.
 *
 * The file is streamed from disk, never loaded into memory. It is always an attachment: the type
 * sent is the one detected when it was stored, nosniff forbids guessing another, and the CSP with
 * `sandbox` keeps anything that was opened anyway from running. Range requests are not supported;
 * a Range header is ignored and the whole file is sent.
 */
final class DownloadAction
{
    public function __construct(
        private readonly FileStore $files,
        private readonly FileAccess $access,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $file = $this->access->canRead(AuthMiddleware::actor($request), $id) ? $this->files->find($id) : null;
        if ($file === null) {
            throw ApiError::notFound();
        }

        $handle = $this->files->open($file);
        if ($handle === null) {
            // A row without its bytes: the volume lost them. The operator needs to know; the client sees a 404.
            $this->logger->error('The bytes of a stored file are missing.', ['file_id' => $file->id]);

            throw ApiError::notFound();
        }

        $body = $this->streams->createStreamFromResource($handle);

        return $this->responses->createResponse(200)
            ->withBody($body)
            ->withHeader('Content-Type', $file->mime)
            ->withHeader('Content-Length', (string) $body->getSize())
            ->withHeader('Content-Disposition', ContentDisposition::attachment($file->name))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('Cache-Control', 'private, no-cache');
    }
}
