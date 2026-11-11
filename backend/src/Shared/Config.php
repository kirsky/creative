<?php

declare(strict_types=1);

namespace App\Shared;

use RuntimeException;

/**
 * Read-only application settings, taken from the environment.
 *
 * Known keys: APP_ENV, DB_HOST, DB_PORT, DB_NAME, DB_TEST_NAME, DB_USER,
 * DB_PASSWORD, UPLOAD_DIR, MAX_UPLOAD_BYTES, TRUSTED_PROXIES, SESSION_COOKIE_SECURE.
 *
 * Only non-secret settings have defaults; credentials and the database
 * location must be provided by the environment.
 */
final class Config
{
    private const DEFAULTS = [
        'APP_ENV' => 'production',
        'UPLOAD_DIR' => '/var/uploads',
        'MAX_UPLOAD_BYTES' => '99000000',
        'TRUSTED_PROXIES' => '172.16.0.0/12,127.0.0.0/8',
        'SESSION_COOKIE_SECURE' => '1',
    ];

    /** @var array<string, string> */
    private readonly array $values;

    /**
     * @param array<string, string> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = $values + self::DEFAULTS;
    }

    public static function fromEnvironment(): self
    {
        // getenv() sees what the PHP-FPM pool and the CLI were started with, and what putenv() changed.
        return new self(getenv());
    }

    public function get(string $key, ?string $default = null): string
    {
        $value = $this->values[$key] ?? $default;

        return $value ?? throw new RuntimeException(sprintf('Configuration key "%s" is not set.', $key));
    }

    /**
     * @param array<string, string> $overrides
     */
    public function with(array $overrides): self
    {
        return new self($overrides + $this->values);
    }
}
