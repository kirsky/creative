<?php

declare(strict_types=1);

namespace App\Shared\Http;

use RuntimeException;

/**
 * An error the client is meant to see: an HTTP status plus a machine-readable code.
 * The API never sends sentences; the frontend translates codes.
 *
 * `$code` is a plain public property only because Exception::$code is untyped and
 * PHP does not let a subclass narrow it to a readonly string. Treat it as read-only.
 */
final class ApiError extends RuntimeException
{
    /** @var string */
    public $code;

    /**
     * @param int $status HTTP status
     * @param string $code error code, e.g. "invalid_transition"
     * @param list<array{field: string, code: string, params: array<string, mixed>}> $fields per-field validation errors
     * @param array<string, mixed> $params values the frontend needs to render the message (e.g. a limit), sent only when not empty
     * @param array<string, string> $headers extra response headers, e.g. Retry-After
     */
    public function __construct(
        public readonly int $status,
        string $code,
        public readonly array $fields = [],
        public readonly array $params = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($code);
        $this->code = $code;
    }

    public static function unauthenticated(): self
    {
        return new self(401, 'unauthenticated');
    }

    public static function forbidden(): self
    {
        return new self(403, 'forbidden');
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found');
    }
}
