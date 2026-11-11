<?php

declare(strict_types=1);

namespace App\Files;

/**
 * The Content-Disposition header of a download: always an attachment, so the browser saves the file
 * and never renders it. Whatever the name contains, the header stays one line: no quote, backslash,
 * CR or LF can reach it.
 */
final class ContentDisposition
{
    /**
     * attachment; filename="<ASCII fallback>"; filename*=UTF-8''<percent-encoded name> (RFC 6266, RFC 5987).
     * Every browser in use reads filename*; the fallback is for old clients and is only a hint.
     */
    public static function attachment(string $name): string
    {
        return sprintf("attachment; filename=\"%s\"; filename*=UTF-8''%s", self::asciiFallback($name), rawurlencode($name));
    }

    /**
     * Letters, digits and a few harmless punctuation marks are kept; every other character
     * (non-ASCII, quote, backslash, control character, percent, semicolon) becomes "_".
     */
    private static function asciiFallback(string $name): string
    {
        // /u: one underscore per character, not per byte; invalid UTF-8 yields null and is replaced as a whole.
        $fallback = preg_replace('~[^A-Za-z0-9 ._()+,=@\[\]-]~u', '_', $name) ?? '_';

        return preg_replace('~_{2,}~', '_', $fallback) ?? '_';
    }
}
