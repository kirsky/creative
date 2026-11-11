<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Config;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Recovers the real client address behind Cloudflare, the host nginx and the container nginx.
 * Sets the request attribute "client_ip" (string) for logging, rate limiting and the audit trail.
 *
 * The peer (REMOTE_ADDR) is the container nginx. Only when it is inside TRUSTED_PROXIES
 * (comma separated CIDRs) are forwarded headers believed; from any other peer they could be forged.
 * Of X-Forwarded-For the right-most address that is not itself a trusted proxy wins, because every
 * proxy appends what it saw and everything to the left was written by the client. X-Real-IP is the
 * fallback, then the peer.
 *
 * To see the visitor and not Cloudflare, add Cloudflare's ranges to TRUSTED_PROXIES.
 */
final class TrustedProxyMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'client_ip';

    /** @var list<array{string, int}> packed network address and prefix length */
    private readonly array $trusted;

    public function __construct(Config $config)
    {
        $this->trusted = self::parseRanges($config->get('TRUSTED_PROXIES'));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $this->clientIp($request)));
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $peer = is_string($remote) ? self::pack($remote) : null;

        if ($peer === null) {
            return '0.0.0.0';
        }

        if (!$this->isTrusted($peer)) {
            return self::text($peer);
        }

        return $this->fromForwardedFor($request) ?? $this->fromRealIp($request) ?? self::text($peer);
    }

    private function fromForwardedFor(ServerRequestInterface $request): ?string
    {
        $entries = explode(',', implode(',', $request->getHeader('X-Forwarded-For')));

        foreach (array_reverse($entries) as $entry) {
            $address = self::pack(trim($entry));

            // Anything unreadable breaks the chain of custody: believe nothing further left.
            if ($address === null) {
                return null;
            }

            if (!$this->isTrusted($address)) {
                return self::text($address);
            }
        }

        return null;
    }

    private function fromRealIp(ServerRequestInterface $request): ?string
    {
        $address = self::pack(trim($request->getHeaderLine('X-Real-IP')));

        return $address === null ? null : self::text($address);
    }

    private function isTrusted(string $address): bool
    {
        foreach ($this->trusted as [$network, $bits]) {
            if (self::inRange($address, $network, $bits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{string, int}>
     */
    private static function parseRanges(string $list): array
    {
        $ranges = [];

        foreach (preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
            $network = self::pack($address);

            if ($network === null) {
                throw new InvalidArgumentException(sprintf('TRUSTED_PROXIES entry "%s" is not an IP address or CIDR range.', $entry));
            }

            $maxBits = strlen($network) * 8;
            if ($prefix === null) {
                $bits = $maxBits;
            } elseif (preg_match('/^\d{1,3}$/', $prefix) === 1 && (int) $prefix <= $maxBits) {
                $bits = (int) $prefix;
            } else {
                throw new InvalidArgumentException(sprintf('TRUSTED_PROXIES entry "%s" has an invalid prefix length.', $entry));
            }

            $ranges[] = [$network, $bits];
        }

        return $ranges;
    }

    /**
     * Binary form of an IP address (4 or 16 bytes), with IPv4-mapped IPv6 reduced to IPv4;
     * null when the text is not an IP address.
     */
    private static function pack(?string $text): ?string
    {
        if ($text === null || filter_var($text, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($text);
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($packed, 12);
        }

        return $packed;
    }

    private static function text(string $packed): string
    {
        return inet_ntop($packed) ?: '0.0.0.0';
    }

    private static function inRange(string $address, string $network, int $bits): bool
    {
        if (strlen($address) !== strlen($network)) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        if (substr($address, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}
