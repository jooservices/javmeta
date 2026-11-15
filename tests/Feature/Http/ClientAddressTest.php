<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class ClientAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/__client', static fn(Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
            'port' => $request->getPort(),
        ]));
    }

    public function test_the_client_address_is_the_connection_address_and_forwarded_for_is_ignored(): void
    {
        $response = $this->call('GET', '/__client', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 10.0.0.1',
        ]);

        $response->assertOk()->assertJsonPath('ip', '203.0.113.9');
    }

    public function test_the_proto_header_set_by_the_gatekeeper_is_still_honoured(): void
    {
        $response = $this->call('GET', '/__client', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response->assertOk()->assertJsonPath('secure', true);
    }

    public function test_forwarded_host_and_port_cannot_change_the_host_the_app_sees(): void
    {
        $response = $this->call('GET', 'http://xcrawler.net/__client', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
            'HTTP_X_FORWARDED_PORT' => '8443',
        ]);

        $response->assertOk()->assertJsonPath('host', 'xcrawler.net');
        self::assertNotSame(8443, $response->json('port'));
    }

    public function test_rotating_forwarded_for_values_does_not_bypass_the_login_throttle(): void
    {
        RateLimiter::clear('203.0.113.77');
        $statuses = [];
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $statuses[] = $this->call('POST', '/login', ['email' => 'nobody@example.test', 'password' => 'wrong'], [], [], [
                'REMOTE_ADDR' => '203.0.113.77',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.' . $attempt,
            ])->getStatusCode();
        }

        self::assertSame(429, $statuses[7], 'The 8th attempt from one address must be throttled whatever X-Forwarded-For says: ' . implode(',', $statuses));
        self::assertContains(429, $statuses);
    }
}
