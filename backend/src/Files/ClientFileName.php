<?php

declare(strict_types=1);

namespace App\Files;

/**
 * What is kept of the name a client gave an uploaded file. The name is a label to show back and is
 * never used in a path; this only makes it safe to store, to put in a header and to display.
 *
 * Unicode is kept (Cyrillic, emoji), so is everything that is just text to a browser, such as
 * quotes and angle brackets: the page shows names as text and the Content-Disposition header
 * encodes them. Removed: invalid UTF-8, everything before the last path separator, control
 * characters, and the invisible bidirectional controls that can make "gnp.exe" read as "exe.png".
 */
final class ClientFileName
{
    /** The longest name stored, in characters (the width of the column). */
    public const int MAX_LENGTH = 255;

    public const string FALLBACK = 'file';

    /** Longest tail, dot included, still taken for an extension worth keeping when the name is cut. */
    private const int MAX_EXTENSION = 17;

    /**
     * Bytes of the given name that are looked at, counted from the end (the end holds the file name
     * and its extension). A real name is far shorter; this keeps a hostile megabyte-long one cheap.
     */
    private const int MAX_INPUT_BYTES = 8192;

    public static function sanitise(?string $name): string
    {
        // Invalid byte sequences (a cut in the middle of a character included) become "?", so that
        // everything after this works on valid UTF-8.
        $name = mb_scrub(substr($name ?? '', -self::MAX_INPUT_BYTES), 'UTF-8');

        // Only the last segment of a path is a file name: "../../x" is "x", "C:\a\b.png" is "b.png".
        $segments = array_values(array_filter(
            preg_split('~[/\\\\]+~u', $name) ?: [],
            static fn(string $segment): bool => $segment !== '',
        ));
        $name = $segments === [] ? '' : $segments[array_key_last($segments)];

        // Bidi controls and the byte order mark vanish; control characters and line or paragraph
        // separators are word breaks; any run of whitespace is one space.
        $name = preg_replace('~[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]~u', '', $name) ?? '';
        $name = preg_replace('~[\p{Cc}\p{Z}]+~u', ' ', $name) ?? '';
        $name = trim($name, ' ');

        // ".", ".." and the like name nothing.
        if (trim($name, '. ') === '') {
            return self::FALLBACK;
        }

        return self::truncate($name);
    }

    /**
     * Cuts to MAX_LENGTH characters, keeping a short extension so the type stays visible.
     */
    private static function truncate(string $name): string
    {
        if (mb_strlen($name, 'UTF-8') <= self::MAX_LENGTH) {
            return $name;
        }

        $extension = '';
        $dot = mb_strrpos($name, '.', 0, 'UTF-8');
        if ($dot !== false && $dot > 0) {
            $tail = mb_substr($name, $dot, null, 'UTF-8');
            if (mb_strlen($tail, 'UTF-8') <= self::MAX_EXTENSION && !str_contains($tail, ' ')) {
                $extension = $tail;
            }
        }

        $base = mb_substr($name, 0, self::MAX_LENGTH - mb_strlen($extension, 'UTF-8'), 'UTF-8');

        // A cut can leave a space or a joiner (half of an emoji sequence) dangling at the end.
        return rtrim($base, " \u{200C}\u{200D}") . $extension;
    }
}
