<?php

declare(strict_types=1);

namespace App\Demo;

use App\Files\FileStore;
use App\Files\StoredFile;
use App\Identity\User;
use App\Shared\Http\ApiError;
use RuntimeException;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/**
 * The files of the demo: small valid PNGs drawn with GD (a coloured card with the file's name on it),
 * stored through FileStore::put() as the user who "uploaded" them, so they are rows and bytes like
 * any other upload.
 */
final class PlaceholderFiles
{
    private const int WIDTH = 320;

    private const int HEIGHT = 180;

    public function __construct(private readonly FileStore $files) {}

    /**
     * @throws ApiError when FileStore refuses the file (it should not, unless MAX_UPLOAD_BYTES is tiny)
     */
    public function store(User $uploader, string $name): StoredFile
    {
        $png = self::png($name);
        $upload = new UploadedFile((new StreamFactory())->createStream($png), $name, 'image/png', strlen($png), UPLOAD_ERR_OK);

        return $this->files->put($uploader, $upload);
    }

    /**
     * A PNG whose colours come from the name, so that files differ from each other and from run to run
     * not at all.
     */
    private static function png(string $name): string
    {
        $image = imagecreate(self::WIDTH, self::HEIGHT);
        if ($image === false) {
            throw new RuntimeException('GD cannot create an image.');
        }

        $hash = crc32($name);
        $channel = static fn(int $shift): int => 40 + (($hash >> $shift) & 0x7F);

        $background = imagecolorallocate($image, $channel(0), $channel(8), $channel(16));
        $accent = imagecolorallocate($image, 255 - $channel(8), 255 - $channel(16), 255 - $channel(0));
        $text = imagecolorallocate($image, 255, 255, 255);
        if ($background === false || $accent === false || $text === false) {
            throw new RuntimeException('GD cannot allocate colours.');
        }

        imagefilledrectangle($image, 0, 0, self::WIDTH, self::HEIGHT, $background);
        imagefilledrectangle($image, 0, self::HEIGHT - 36, self::WIDTH, self::HEIGHT, $accent);
        imagefilledellipse($image, self::WIDTH - 60, 60, 70, 70, $accent);
        // GD's built-in fonts are Latin-1; the names are ASCII file names.
        imagestring($image, 3, 12, 12, substr(preg_replace('/[^\x20-\x7e]/', '?', $name) ?? '', 0, 40), $text);

        ob_start();
        try {
            imagepng($image, null, 6);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return is_string($bytes) && $bytes !== '' ? $bytes : throw new RuntimeException('GD produced no PNG.');
    }
}
