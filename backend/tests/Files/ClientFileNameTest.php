<?php

declare(strict_types=1);

namespace App\Tests\Files;

use App\Files\ClientFileName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the client calls a file is only ever text to show back. This is the whole of what is kept of it.
 */
final class ClientFileNameTest extends TestCase
{
    #[DataProvider('names')]
    public function test_sanitise(?string $given, string $expected): void
    {
        self::assertSame($expected, ClientFileName::sanitise($given));
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function names(): array
    {
        return [
            'plain' => ['banner.png', 'banner.png'],
            'unicode is kept' => ['мой файл (1).png', 'мой файл (1).png'],
            'emoji is kept' => ['🎰 лото.pdf', '🎰 лото.pdf'],
            'html is kept as text' => ['<img src=x onerror=alert(1)>.png', '<img src=x onerror=alert(1)>.png'],
            'a closing tag is a path separator too' => ['<b>x</b>.png', 'b>.png'],
            'quotes are kept (the header encodes them)' => ['a"b\'c.png', 'a"b\'c.png'],
            'unix traversal' => ['../../x', 'x'],
            'windows traversal' => ['..\\..\\windows\\x.png', 'x.png'],
            'drive letter' => ['C:\\Users\\me\\x.png', 'x.png'],
            'absolute path' => ['/etc/passwd', 'passwd'],
            'trailing separator' => ['dir/name/', 'name'],
            'mixed separators' => ['a/b\\c/d.png', 'd.png'],
            'only separators' => ['////', 'file'],
            'dot' => ['.', 'file'],
            'dots' => ['..', 'file'],
            'dots and spaces' => [' . . ', 'file'],
            'empty' => ['', 'file'],
            'null' => [null, 'file'],
            'whitespace only' => ["  \t\n ", 'file'],
            'nul byte' => ["nul\0byte.png", 'nul byte.png'],
            'cr lf' => ["a\r\nb.png", 'a b.png'],
            'tab and vertical tab' => ["a\tb\x0Bc.png", 'a b c.png'],
            'other control characters' => ["a\x01\x1F\x7Fb.png", 'a b.png'],
            'line separator' => ["a\u{2028}b\u{2029}c.png", 'a b c.png'],
            'whitespace is collapsed and trimmed' => ["  a    b \t c.png  ", 'a b c.png'],
            'no-break space' => ["a\u{00A0}\u{00A0}b.png", 'a b.png'],
            'bidi override cannot disguise an extension' => ["gnp.\u{202E}exe", 'gnp.exe'],
            'zero-width joiner of an emoji sequence is kept' => ["👩\u{200D}🎨.png", "👩\u{200D}🎨.png"],
            'byte order mark' => ["\u{FEFF}a.png", 'a.png'],
            'invalid utf-8' => ["a\xFF\xFE.png", 'a??.png'],
            'hidden file name is only text' => ['.htaccess', '.htaccess'],
            'exactly 255 characters' => [str_repeat('я', 251) . '.png', str_repeat('я', 251) . '.png'],
            '256 characters keep the extension' => [str_repeat('я', 252) . '.png', str_repeat('я', 251) . '.png'],
            '300 cyrillic characters keep the extension' => [str_repeat('ж', 296) . '.png', str_repeat('ж', 251) . '.png'],
            'only the last extension is kept' => [str_repeat('a', 300) . '.tar.gz', str_repeat('a', 252) . '.gz'],
            'four-byte characters count as one' => [str_repeat('🎰', 300) . '.png', str_repeat('🎰', 251) . '.png'],
            'a long name without extension' => [str_repeat('a', 300), str_repeat('a', 255)],
            'an extension that is itself too long is not one' => ['a.' . str_repeat('b', 300), 'a.' . str_repeat('b', 253)],
            'a leading dot is not an extension' => ['.' . str_repeat('a', 300), '.' . str_repeat('a', 254)],
            'no dangling space after the cut' => [str_repeat('a', 250) . ' bbbbbbbbb.png', str_repeat('a', 250) . '.png'],
        ];
    }

    public function test_a_huge_name_is_cut_down_cheaply_and_keeps_its_extension(): void
    {
        $name = ClientFileName::sanitise(str_repeat('я', 2_000_000) . '.png');

        self::assertSame(255, mb_strlen($name));
        self::assertStringEndsWith('.png', $name);
        self::assertTrue(mb_check_encoding($name, 'UTF-8'));
    }

    public function test_the_result_is_never_empty_and_never_too_long(): void
    {
        foreach (['', ' ', '.', '..', '/', "\0", "\u{202E}", str_repeat('é', 1000), "\xFF"] as $given) {
            $name = ClientFileName::sanitise($given);

            self::assertNotSame('', $name);
            self::assertLessThanOrEqual(255, mb_strlen($name));
            self::assertTrue(mb_check_encoding($name, 'UTF-8'));
            self::assertDoesNotMatchRegularExpression('~[\p{Cc}/\\\\]~u', $name);
        }
    }
}
