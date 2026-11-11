<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigTest extends TestCase
{
    public function test_get_returns_a_configured_value(): void
    {
        self::assertSame('db', (new Config(['DB_HOST' => 'db']))->get('DB_HOST'));
    }

    public function test_an_explicit_default_is_used_for_an_unset_key(): void
    {
        self::assertSame('fallback', (new Config([]))->get('UNSET_KEY', 'fallback'));
    }

    public function test_a_configured_value_beats_the_default(): void
    {
        self::assertSame('db', (new Config(['DB_HOST' => 'db']))->get('DB_HOST', 'other'));
    }

    public function test_an_unset_key_without_default_fails_without_echoing_other_values(): void
    {
        $config = new Config(['DB_PASSWORD' => 'hunter2']);

        try {
            $config->get('DB_HOST');
            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('DB_HOST', $e->getMessage());
            self::assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    public function test_safe_defaults_exist_for_non_secret_settings(): void
    {
        $config = new Config([]);

        self::assertSame('production', $config->get('APP_ENV'));
        self::assertSame('172.16.0.0/12,127.0.0.0/8', $config->get('TRUSTED_PROXIES'));
        self::assertSame('99000000', $config->get('MAX_UPLOAD_BYTES'));
        self::assertSame('1', $config->get('SESSION_COOKIE_SECURE'));
    }

    public function test_credentials_have_no_default(): void
    {
        $this->expectException(RuntimeException::class);
        (new Config([]))->get('DB_PASSWORD');
    }

    public function test_the_environment_is_read_through_getenv(): void
    {
        putenv('CONFIG_TEST_KEY=from-env');

        try {
            self::assertSame('from-env', Config::fromEnvironment()->get('CONFIG_TEST_KEY'));
        } finally {
            putenv('CONFIG_TEST_KEY');
        }
    }

    public function test_with_returns_a_modified_copy(): void
    {
        $original = new Config(['DB_NAME' => 'creative']);
        $copy = $original->with(['DB_NAME' => 'creative_test']);

        self::assertSame('creative', $original->get('DB_NAME'));
        self::assertSame('creative_test', $copy->get('DB_NAME'));
    }
}
