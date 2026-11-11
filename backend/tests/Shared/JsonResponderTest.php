<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Http\JsonResponder;
use JsonException;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;

final class JsonResponderTest extends TestCase
{
    private const TEXT = '🎰 <script>alert(1)</script> Лото';

    public function test_emoji_cyrillic_and_markup_round_trip_byte_identical(): void
    {
        $response = $this->responder()->respond(['title' => self::TEXT]);
        $body = (string) $response->getBody();

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(self::TEXT, $decoded['title']);
        self::assertSame(bin2hex(self::TEXT), bin2hex((string) $decoded['title']));
    }

    public function test_unicode_is_sent_readable_but_markup_never_appears_literally(): void
    {
        $body = (string) $this->responder()->respond(['title' => self::TEXT])->getBody();

        self::assertStringContainsString('Лото', $body);
        self::assertStringContainsString('🎰', $body);
        self::assertStringNotContainsString('<script>', $body);
    }

    public function test_status_and_headers(): void
    {
        $response = $this->responder()->respond(['ok' => true], 201, ['Location' => '/api/tasks/7']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('/api/tasks/7', $response->getHeaderLine('Location'));
    }

    public function test_invalid_utf8_is_an_error_not_silently_mangled(): void
    {
        $this->expectException(JsonException::class);
        $this->responder()->respond(['title' => "\xB1\x31"]);
    }

    private function responder(): JsonResponder
    {
        return new JsonResponder(new ResponseFactory());
    }
}
