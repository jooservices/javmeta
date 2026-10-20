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

    public function test_it_does_not_send_spans_when_trace_sampling_is_disabled(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.trace_sample_rate' => 0,
        ]);
        Http::fake();

        $client = new OpenObserveClient(new TelemetrySanitizer());
        $client->ingestSpan([
            'name' => 'crawl.detail',
            'trace_id' => str_repeat('a', 32),
            'span_id' => str_repeat('b', 16),
            'start_ns' => 1,
            'end_ns' => 2,
        ]);

        Http::assertNothingSent();
    }

    public function test_it_sends_parent_span_when_non_null_attributes_remain_after_sanitizing(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.trace_sample_rate' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response([], 200)]);

        $client = new OpenObserveClient(new TelemetrySanitizer());
        $client->ingestSpan([
            'name' => 'crawl.detail',
            'trace_id' => str_repeat('a', 32),
            'span_id' => str_repeat('b', 16),
            'parent_span_id' => str_repeat('c', 16),
            'start_ns' => 1,
            'end_ns' => 2,
            'status_code' => 503,
            'attributes' => ['operation' => 'fetch', 'empty' => null, 'api_key' => 'test-only-value'],
        ]);

        Http::assertSent(static function (ClientRequest $request): bool {
            $span = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

            return $request->url() === 'http://openobserve.test/api/default/v1/traces'
                && ($span['parentSpanId'] ?? null) === str_repeat('c', 16)
                && $span['status']['code'] === 2
                && $span['attributes'] === [[
                    'key' => 'operation',
                    'value' => ['stringValue' => 'fetch'],
                ]];
        });
    }

    public function test_it_encodes_the_organization_when_sending_metric_labels(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'jav meta',
            'openobserve.service_name' => 'javmeta',
            'app.env' => 'staging',
            'dependencies.role' => 'scheduler',
        ]);
        Http::fake(['openobserve.test/*' => Http::response([], 200)]);

        $client = new OpenObserveClient(new TelemetrySanitizer());
        $client->ingestMetrics([
            [
                'name' => 'crawl.items',
                'value' => 3,
                'labels' => ['source' => 'javdb'],
            ],
            [
                'name' => 'crawl.errors',
                'value' => 1,
                'labels' => ['source' => 'onejav', 'environment' => 'production', 'role' => 'api'],
            ],
        ]);

        Http::assertSent(static function (ClientRequest $request): bool {
            $metrics = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'];
            $metric = $metrics[0];
            $secondAttributes = $metrics[1]['gauge']['dataPoints'][0]['attributes'] ?? [];

            return $request->url() === 'http://openobserve.test/api/jav%20meta/v1/metrics'
                && count($metrics) === 2
                && $metric['name'] === 'crawl.items'
                && $metric['gauge']['dataPoints'][0]['asDouble'] === 3.0
                && $metric['gauge']['dataPoints'][0]['attributes'] === [[
                    'key' => 'source',
                    'value' => ['stringValue' => 'javdb'],
                ], [
                    'key' => 'environment',
                    'value' => ['stringValue' => 'staging'],
                ], [
                    'key' => 'role',
                    'value' => ['stringValue' => 'scheduler'],
                ]]
                && collect($secondAttributes)->contains([
                    'key' => 'environment',
                    'value' => ['stringValue' => 'staging'],
                ])
                && collect($secondAttributes)->contains([
                    'key' => 'role',
                    'value' => ['stringValue' => 'scheduler'],
                ]);
        });
    }

    public function test_every_log_record_is_labeled_with_the_configured_environment_and_role(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
            'openobserve.service_name' => 'javmeta',
            'app.env' => 'staging',
            'dependencies.role' => 'worker',
        ]);
        Http::fake(['openobserve.test/*' => Http::response([], 200)]);

        $client = new OpenObserveClient(new TelemetrySanitizer());
        $client->ingestLogs([
            ['message' => 'first', 'level' => 'info'],
            ['message' => 'second', 'level' => 'warning', 'environment' => 'local', 'role' => 'api'],
        ]);

        Http::assertSent(static function (ClientRequest $request): bool {
            $records = $request->data();

            return $request->url() === 'http://openobserve.test/api/default/javmeta_logs/_json'
                && count($records) === 2
                && collect($records)->every(static fn(array $record): bool => ($record['environment'] ?? null) === 'staging'
                    && ($record['role'] ?? null) === 'worker'
                    && ($record['service'] ?? null) === 'javmeta');
        });
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
