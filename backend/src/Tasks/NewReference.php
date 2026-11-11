<?php

declare(strict_types=1);

namespace App\Tasks;

use LogicException;

/**
 * A reference of a brief that is about to be stored: a link, or an uploaded file by its id.
 */
final readonly class NewReference
{
    private function __construct(
        public ?string $url,
        public ?int $fileId,
    ) {}

    public static function url(string $url): self
    {
        return new self($url, null);
    }

    public static function file(int $fileId): self
    {
        return new self(null, $fileId);
    }

    /**
     * The file id of a file reference.
     */
    public function requireFileId(): int
    {
        return $this->fileId ?? throw new LogicException('Not a file reference.');
    }
}
