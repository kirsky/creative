<?php

declare(strict_types=1);

namespace App\Tests\Files;

use RuntimeException;

/**
 * Small real files of every allowed type, and some that must be refused. Images come from GD, the
 * rest are the smallest byte sequences libmagic still recognises.
 */
final class Samples
{
    public static function png(int $size = 2): string
    {
        return self::image('imagepng', $size);
    }

    public static function jpeg(): string
    {
        return self::image('imagejpeg');
    }

    public static function gif(): string
    {
        return self::image('imagegif');
    }

    public static function webp(): string
    {
        return self::image('imagewebp');
    }

    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    /** An empty archive: just the end-of-central-directory record. */
    public static function zip(): string
    {
        return "PK\x05\x06" . str_repeat("\0", 18);
    }

    public static function photoshop(): string
    {
        return "8BPS\x00\x01" . str_repeat("\0", 6) . "\x00\x03\x00\x00\x00\x10\x00\x00\x00\x10\x00\x08\x00\x03" . str_repeat("\0", 40);
    }

    public static function mp4(): string
    {
        return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\0", 32);
    }

    public static function webm(): string
    {
        return "\x1a\x45\xdf\xa3\x01\x00\x00\x00\x00\x00\x00\x1f\x42\x86\x81\x01\x42\xf7\x81\x01\x42\xf2\x81\x04\x42\xf3\x81\x08\x42\x82\x84webm";
    }

    public static function mp3(): string
    {
        return "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xff\xfb\x90\x00", 50);
    }

    public static function wav(): string
    {
        return "RIFF\x24\x00\x00\x00WAVEfmt \x10\x00\x00\x00\x01\x00\x01\x00\x44\xac\x00\x00\x88\x58\x01\x00\x02\x00\x10\x00data\x00\x00\x00\x00";
    }

    public static function phpSource(): string
    {
        return "<?php\nsystem(\$_GET['c']);\n";
    }

    public static function svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>';
    }

    public static function html(): string
    {
        return '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>';
    }

    /**
     * A valid PNG padded with zeros to exactly $length bytes (trailing bytes do not change what libmagic sees).
     */
    public static function pngOfLength(int $length): string
    {
        $png = self::png();
        if (strlen($png) > $length) {
            throw new RuntimeException('The length is too small for a PNG.');
        }

        return $png . str_repeat("\0", $length - strlen($png));
    }

    /**
     * @param callable(\GdImage): mixed $encode
     */
    private static function image(callable $encode, int $size = 2): string
    {
        $image = imagecreatetruecolor(max(1, $size), max(1, $size));
        if ($image === false) {
            throw new RuntimeException('GD cannot create an image.');
        }

        ob_start();
        $encode($image);

        return (string) ob_get_clean();
    }
}
