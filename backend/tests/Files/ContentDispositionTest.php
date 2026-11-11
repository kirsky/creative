<?php

declare(strict_types=1);

namespace App\Tests\Files;

use App\Files\ContentDisposition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentDispositionTest extends TestCase
{
    #[DataProvider('names')]
    public function test_attachment(string $name, string $expected): void
    {
        self::assertSame($expected, ContentDisposition::attachment($name));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'plain' => ['report.pdf', "attachment; filename=\"report.pdf\"; filename*=UTF-8''report.pdf"],
            'cyrillic' => [
                'мой файл (1).png',
                "attachment; filename=\"_ _ (1).png\"; filename*=UTF-8''%D0%BC%D0%BE%D0%B9%20%D1%84%D0%B0%D0%B9%D0%BB%20%281%29.png",
            ],
            'quote' => ['a"b.png', "attachment; filename=\"a_b.png\"; filename*=UTF-8''a%22b.png"],
            'backslash' => ['a\\b.png', "attachment; filename=\"a_b.png\"; filename*=UTF-8''a%5Cb.png"],
            'cr lf' => ["a\r\nb.png", "attachment; filename=\"a_b.png\"; filename*=UTF-8''a%0D%0Ab.png"],
            'percent' => ['100%.png', "attachment; filename=\"100_.png\"; filename*=UTF-8''100%25.png"],
            'semicolon and apostrophe' => ["a;b'c.png", "attachment; filename=\"a_b_c.png\"; filename*=UTF-8''a%3Bb%27c.png"],
            'emoji' => ['🎰.png', "attachment; filename=\"_.png\"; filename*=UTF-8''%F0%9F%8E%B0.png"],
            'path separators' => ['../x', "attachment; filename=\".._x\"; filename*=UTF-8''..%2Fx"],
        ];
    }

    public function test_no_input_can_break_out_of_the_header(): void
    {
        foreach (["a\"; filename=\"evil", "a\r\nX-Injected: 1", "\0", "\x7F", "\u{2028}", str_repeat("\"\\", 50), 'é'] as $name) {
            $header = ContentDisposition::attachment($name);

            self::assertMatchesRegularExpression('#^attachment; filename="[A-Za-z0-9 ._()+,=@\[\]_-]*"; filename\*=UTF-8\'\'[A-Za-z0-9%._~-]*$#', $header, bin2hex($name));
        }
    }
}
