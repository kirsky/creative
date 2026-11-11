<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\ApiTestCase;

final class HealthTest extends ApiTestCase
{
    public function test_health_is_public_and_matches_spec(): void
    {
        // No session, no CSRF token, no credentials of any kind.
        $response = $this->request('GET', '/api/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok"}', (string) $response->getBody());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertMatchesSpec($response);
    }

    public function test_health_returns_no_data_beyond_the_status(): void
    {
        $response = $this->request('GET', '/api/health');

        self::assertSame(['status' => 'ok'], self::decodeJson($response));
    }
}
