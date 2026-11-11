<?php

declare(strict_types=1);

namespace App\Identity;

use App\Shared\Config;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;

/**
 * The session cookie: creative_session, HttpOnly, SameSite=Lax, Path=/, and Secure unless
 * SESSION_COOKIE_SECURE is "0" (it is "1" by default and in .env.example; any other value keeps it on).
 * It has no Max-Age: the server decides when a session ends.
 */
final class SessionCookie
{
    public const NAME = 'creative_session';

    private readonly bool $secure;

    public function __construct(Config $config)
    {
        $this->secure = $config->get('SESSION_COOKIE_SECURE') !== '0';
    }

    /**
     * The token the request presents, or null when it has none.
     */
    public static function read(ServerRequestInterface $request): ?string
    {
        $value = $request->getCookieParams()[self::NAME] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The Set-Cookie value that hands $token to the browser.
     */
    public function issue(#[SensitiveParameter] string $token): string
    {
        return $this->header($token, []);
    }

    /**
     * The Set-Cookie value that makes the browser drop the cookie.
     */
    public function clear(): string
    {
        return $this->header('', ['Max-Age=0']);
    }

    /**
     * @param list<string> $extra
     */
    private function header(#[SensitiveParameter] string $value, array $extra): string
    {
        $attributes = [...$extra, 'Path=/', 'HttpOnly'];
        if ($this->secure) {
            $attributes[] = 'Secure';
        }
        $attributes[] = 'SameSite=Lax';

        return self::NAME . '=' . $value . '; ' . implode('; ', $attributes);
    }
}
