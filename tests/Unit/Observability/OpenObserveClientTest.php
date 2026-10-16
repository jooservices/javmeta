<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use App\Observability\OpenObserveClient;
use App\Observability\TelemetrySanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OpenObserveClientTest extends TestCase
{
    public function test_disabled_client_does_not_http(): void
    {
        config()->set('openobserve.enabled', false);
        Http::fake();

        $client = new OpenObserveClient(new TelemetrySanitizer());
        $client->ingestLogs([['message' => 'hello', 'level' => 'info']]);
        $client->ingestMetrics([['name' => 'm', 'value' => 1]]);
        $client->ingestSpan([
            'name' => 'span',
            'trace_id' => str_repeat('a', 32),
            'span_id' => str_repeat('b', 16),
            'start_ns' => 1,
            'end_ns' => 2,
        ]);

        Http::assertNothingSent();
    }

    public function test_fail_open_on_http_error(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
        ]);
        Http::fake([
            'openobserve.test/*' => Http::response(['error' => 'no'], 500),
        ]);

        $client = new OpenObserveClient(new TelemetrySanitizer());
        $client->ingestLogs([['message' => 'x', 'level' => 'info']]);

        Http::assertSentCount(1);
        $this->assertTrue(true);
    }

    public function test_unreachable_endpoint_does_not_hold_log_shipping_for_200_milliseconds(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
            'openobserve.timeout' => 3,
        ]);
        Http::fake(static function (ClientRequest $request): never {
            throw new ConnectionException('Connection refused.');
        });

        $client = new OpenObserveClient(new TelemetrySanitizer());
        $startedAt = hrtime(true);
        $client->ingestLogs([['message' => fake()->sentence(4), 'level' => 'warning']]);
        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        $this->assertLessThan(200, $elapsedMilliseconds);
    }

    public function test_log_buffer_overflow_is_counted(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
            'openobserve.log_buffer_capacity' => 2,
        ]);
        Http::fake(static function (ClientRequest $request): never {
            throw new ConnectionException('Connection refused.');
        });

        $client = new OpenObserveClient(new TelemetrySanitizer());
        $client->ingestLogs([
            ['message' => fake()->sentence(4), 'level' => 'warning'],
            ['message' => fake()->sentence(4), 'level' => 'warning'],
            ['message' => fake()->sentence(4), 'level' => 'warning'],
        ]);
        $client->ingestLogs([['message' => fake()->sentence(4), 'level' => 'warning']]);

        $this->assertSame(2, $client->droppedLogCount());
    }

    public function test_circuit_breaker_suppresses_requests_until_the_cooldown_expires(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
        ]);
        $attempts = 0;
        Http::fake(static function (ClientRequest $request) use (&$attempts) {
            $attempts++;
            if ($attempts < 4) {
                throw new ConnectionException('Connection refused.');
            }

            return Http::response([], 200);
        });

        $client = new OpenObserveClient(new TelemetrySanitizer());
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $client->ingestLogs([['message' => fake()->sentence(4), 'level' => 'warning']]);
        }
        $client->ingestLogs([['message' => fake()->sentence(4), 'level' => 'warning']]);
        $this->assertSame(3, $attempts);

        \Illuminate\Support\Carbon::setTestNow(now()->addSeconds(30));
        $client->ingestLogs([['message' => fake()->sentence(4), 'level' => 'warning']]);
        \Illuminate\Support\Carbon::setTestNow();

        $this->assertSame(4, $attempts);
    }
}
