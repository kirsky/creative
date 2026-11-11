<?php

declare(strict_types=1);

namespace App\Files;

/**
 * What is known about an uploaded file. $name is the sanitised client name (a label to show), $mime
 * what the bytes were detected to be, $size the number of bytes stored. Where the bytes live is
 * FileStore's business and is not part of this value.
 */
final readonly class StoredFile
{
    public function __construct(
        public int $id,
        public string $name,
        public string $mime,
        public int $size,
        public int $uploaderId,
    ) {}
}
