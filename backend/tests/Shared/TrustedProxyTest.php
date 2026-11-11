<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Config;
use App\Shared\Http\TrustedProxyMiddleware;
use App\Tests\Support\CapturingHandler;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class TrustedProxyTest extends TestCase
{
    private const TRUSTED = '172.16.0.0/12,127.0.0.0/8';

    public function test_x_real_ip_used_only_from_trusted_proxy(): void
    {
        // The Docker bridge gateway is a trusted proxy: the header is honoured.
        self::assertSame('203.0.113.7', $this->clientIp('172.18.0.5', ['X-Real-IP' => '203.0.113.7']));

        // Any other peer could have made the header up: it is ignored.
        self::assertSame('8.8.8.8', $this->clientIp('8.8.8.8', ['X-Real-IP' => '203.0.113.7']));
    }

    public function test_no_forwarded_headers_means_the_peer_address(): void
    {
        self::assertSame('172.18.0.1', $this->clientIp('172.18.0.1'));
    }

    public function test_forwarded_for_takes_the_right_most_address_that_is_not_a_trusted_proxy(): void
    {
        // The client wrote the left-most entries; each proxy appended what it saw.
        self::assertSame(
            '203.0.113.7',
            $this->clientIp('172.18.0.1', ['X-Forwarded-For' => '1.1.1.1, 2.2.2.2, 203.0.113.7, 172.18.0.9']),
        );
    }

    public function test_a_spoofed_left_most_forwarded_for_entry_is_ignored(): void
    {
        self::assertSame(
            '203.0.113.7',
            $this->clientIp('172.18.0.1', ['X-Forwarded-For' => '10.0.0.1, 203.0.113.7']),
        );
    }

    public function test_forwarded_for_is_ignored_from_an_untrusted_peer(): void
    {
        self::assertSame(
            '8.8.8.8',
            $this->clientIp('8.8.8.8', ['X-Forwarded-For' => '203.0.113.7', 'X-Real-IP' => '203.0.113.8']),
        );
    }

    public function test_forwarded_for_wins_over_x_real_ip_because_it_cannot_be_spoofed_from_the_left(): void
    {
        self::assertSame(
            '203.0.113.7',
            $this->clientIp('172.18.0.1', ['X-Forwarded-For' => '203.0.113.7', 'X-Real-IP' => '198.51.100.1']),
        );
    }

    public function test_repeated_forwarded_for_header_lines_are_joined(): void
    {
        $request = $this->request('172.18.0.1')
            ->withAddedHeader('X-Forwarded-For', '1.1.1.1')
            ->withAddedHeader('X-Forwarded-For', '203.0.113.7');

        self::assertSame('203.0.113.7', $this->process($request));
    }

    public function test_a_garbage_header_falls_back_to_the_peer_address(): void
    {
        self::assertSame('172.18.0.1', $this->clientIp('172.18.0.1', ['X-Real-IP' => 'not-an-ip']));
        self::assertSame('172.18.0.1', $this->clientIp('172.18.0.1', ['X-Forwarded-For' => '203.0.113.7, unknown']));
        self::assertSame('172.18.0.1', $this->clientIp('172.18.0.1', ['X-Forwarded-For' => '']));
    }

    public function test_when_every_forwarded_address_is_a_proxy_the_next_header_is_tried(): void
    {
        self::assertSame(
            '203.0.113.7',
            $this->clientIp('172.18.0.1', ['X-Forwarded-For' => '172.18.0.2, 127.0.0.1', 'X-Real-IP' => '203.0.113.7']),
        );
    }

    public function test_ipv6_clients_and_proxies(): void
    {
        $trusted = '172.16.0.0/12,fd00::/8';

        self::assertSame(
            '2001:db8::7',
            $this->clientIp('fd00::5', ['X-Forwarded-For' => '2001:db8::7, fd00::9'], $trusted),
        );
        self::assertSame('2001:db8::1', $this->clientIp('2001:db8::1', ['X-Real-IP' => '203.0.113.7'], $trusted));
    }

    public function test_an_ipv4_mapped_ipv6_peer_matches_its_ipv4_range(): void
    {
        self::assertSame('203.0.113.7', $this->clientIp('::ffff:172.18.0.5', ['X-Real-IP' => '203.0.113.7']));
    }

    public function test_the_peer_address_is_used_for_cli_style_requests_without_one(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/', []);

        self::assertSame('0.0.0.0', $this->process($request));
    }

    #[DataProvider('invalidTrustedProxyLists')]
    public function test_a_malformed_trusted_proxy_list_fails_loudly(string $list): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TrustedProxyMiddleware(new Config(['TRUSTED_PROXIES' => $list]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTrustedProxyLists(): iterable
    {
        yield 'not an address' => ['nginx'];
        yield 'prefix out of range' => ['10.0.0.0/33'];
        yield 'prefix not a number' => ['10.0.0.0/x'];
    }

    public function test_the_default_trusts_docker_ranges_only(): void
    {
        $middleware = new TrustedProxyMiddleware(new Config([]));

        $handler = new CapturingHandler();
        $middleware->process($this->request('172.20.0.3')->withHeader('X-Real-IP', '203.0.113.7'), $handler);
        self::assertSame('203.0.113.7', $handler->request()->getAttribute('client_ip'));

        $handler = new CapturingHandler();
        $middleware->process($this->request('8.8.8.8')->withHeader('X-Real-IP', '203.0.113.7'), $handler);
        self::assertSame('8.8.8.8', $handler->request()->getAttribute('client_ip'));
    }

    /**
     * @param array<string, string> $headers
     */
    private function clientIp(string $peer, array $headers = [], string $trusted = self::TRUSTED): string
    {
        $request = $this->request($peer);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->process($request, $trusted);
    }

    private function process(ServerRequestInterface $request, string $trusted = self::TRUSTED): string
    {
        $middleware = new TrustedProxyMiddleware(new Config(['TRUSTED_PROXIES' => $trusted]));
        $handler = new CapturingHandler();
        $middleware->process($request, $handler);

        $ip = $handler->request()->getAttribute('client_ip');
        self::assertIsString($ip);

        return $ip;
    }

    private function request(string $peer): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/health', ['REMOTE_ADDR' => $peer]);
    }
}
