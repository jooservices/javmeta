<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Observability;

use App\Services\Observability\NodeEgressProbe;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JOOservices\Client\Client\ClientBuilder;
use JOOservices\Client\Testing\FakeTransport;
use Nyholm\Psr7\Response;
use RuntimeException;
use Tests\TestCase;

final class NodeEgressProbeTest extends TestCase
{
    public function test_node_probe_falls_back_to_next_echo_url_and_caches_until_due(): void
    {
        $this->prepareTelemetry();
        config()->set([
            'javmeta_observability.egress_probe_interval_seconds' => 120,
            'javmeta_observability.egress_probe_timeout_seconds' => 5,
            'javmeta_observability.egress_probe_urls' => ['https://echo-one.test', 'https://echo-two.test'],
        ]);
        Cache::setDefaultDriver('array');
        $transport = $this->fakeTransport(
            new Response(200, [], 'not an address'),
            new Response(200, [], "198.51.100.10\n"),
        );

        $probe = app(NodeEgressProbe::class);
        $probe->probeNodeIfDue();
        $node = $probe->nodeStatuses()[0]['node'];

        self::assertSame('198.51.100.10', $probe->currentNodeIp());
        self::assertSame(1, $probe->nodeStatuses()[0]['change_count']);
        self::assertCount(2, $transport->recorded());
        foreach ($transport->recorded() as $recorded) {
            self::assertLessThanOrEqual(5, $recorded['options']->timeout);
            self::assertLessThanOrEqual(5, $recorded['options']->connectTimeout);
            self::assertSame('', $recorded['options']->proxy);
        }

        $probe->probeNodeIfDue();
        self::assertCount(2, $transport->recorded());
        self::assertSame('198.51.100.10', Cache::get('javmeta:egress:' . $node)['ip']);
        $this->travel(241)->seconds();
        self::assertNull($probe->currentNodeIp());
    }

    public function test_timeout_exception_falls_back_without_escaping_the_probe(): void
    {
        $this->prepareTelemetry();
        config()->set('javmeta_observability.egress_probe_urls', ['https://timeout.test', 'https://fallback.test']);
        $transport = $this->fakeTransport(new RuntimeException('timeout'), new Response(200, [], '192.0.2.25'));

        $probe = app(NodeEgressProbe::class);
        $probe->probeNodeIfDue();

        self::assertSame('192.0.2.25', $probe->currentNodeIp());
        self::assertCount(2, $transport->recorded());
    }

    public function test_probe_change_updates_counter_and_emits_a_structured_log(): void
    {
        $this->prepareTelemetry();
        config()->set([
            'javmeta_observability.egress_probe_interval_seconds' => 120,
            'javmeta_observability.egress_probe_urls' => ['https://echo.test'],
        ]);
        Cache::setDefaultDriver('array');
        $transport = $this->fakeTransport(new Response(200, [], '198.51.100.20'), new Response(200, [], '198.51.100.21'));

        $probe = app(NodeEgressProbe::class);
        $probe->probeNodeIfDue();
        Cache::forget('javmeta:egress:' . $probe->nodeStatuses()[0]['node']);
        $probe->probeNodeIfDue();

        self::assertSame('198.51.100.21', $probe->currentNodeIp());
        self::assertSame(2, $probe->nodeStatuses()[0]['change_count']);
        self::assertCount(2, $transport->recorded());
        $records = Http::recorded()
            ->map(static fn(array $pair) => $pair[0])
            ->filter(static fn($request): bool => str_contains($request->url(), '/egress-test-logs/_json'))
            ->flatMap(static fn($request): array => $request->data())
            ->values();
        self::assertCount(2, $records);
        self::assertSame('egress_ip_changed', $records[1]['event']);
        self::assertSame('198.51.100.20', $records[1]['old_ip']);
        self::assertSame('198.51.100.21', $records[1]['new_ip']);
        self::assertNotEmpty($records[1]['node']);
        self::assertNotEmpty($records[1]['changed_at']);
    }

    public function test_invalid_echo_responses_fail_open_without_a_cached_ip(): void
    {
        config()->set([
            'cache.default' => 'array',
            'javmeta_observability.egress_probe_urls' => ['https://unavailable.test', 'https://ipv6.test'],
        ]);
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        $transport = $this->fakeTransport(new Response(503), new Response(200, [], '2001:db8::1'));

        $probe = app(NodeEgressProbe::class);
        $probe->probeNodeIfDue();

        self::assertNull($probe->currentNodeIp());
        self::assertFalse($probe->nodeStatuses()[0]['probe_ok']);
        self::assertCount(2, $transport->recorded());
    }

    public function test_browser_host_probe_uses_playwright_and_caches_the_browser_egress_ip(): void
    {
        $this->prepareTelemetry();
        config()->set([
            'javmeta_browser.playwright_url' => 'http://browser-a.test',
            'javmeta_browser.flaresolverr_url' => '',
            'javmeta_observability.egress_probe_urls' => ['https://ifconfig.me'],
        ]);
        Cache::setDefaultDriver('array');
        $transport = $this->fakeTransport(new Response(200, [], json_encode([
            'exitCode' => 0,
            'stdout' => json_encode(['html' => "203.0.113.40\n"], JSON_THROW_ON_ERROR),
        ], JSON_THROW_ON_ERROR)));

        $probe = app(NodeEgressProbe::class);
        $probe->probeBrowserHostsIfDue();

        self::assertSame('203.0.113.40', $probe->currentBrowserIp('browser-a.test'));
        self::assertSame('203.0.113.40', $probe->browserStatuses()[0]['ip']);
        self::assertSame('browser_host', $probe->contextForLane('browser')['egress_kind']);
        self::assertSame('203.0.113.40', $probe->contextForLane('browser')['egress_ip']);
        self::assertCount(1, $transport->recorded());
        self::assertLessThanOrEqual(5, $transport->recorded()[0]['options']->timeout);
    }

    private function prepareTelemetry(): void
    {
        config()->set([
            'cache.default' => 'array',
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'egress-test-logs',
            'javmeta_observability.egress_changes_stream' => 'egress-test-logs',
            'openobserve.email' => 'test@example.test',
            'openobserve.password' => 'test-only',
            'openobserve.timeout' => 1,
        ]);
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Http::fake(['openobserve.test/*' => Http::response([], 200)]);
    }

    private function fakeTransport(Response|RuntimeException ...$responses): FakeTransport
    {
        $transport = new FakeTransport();
        foreach ($responses as $response) {
            $transport->push($response);
        }
        App::forgetInstance(NodeEgressProbe::class);
        App::instance(ClientBuilder::class, ClientBuilder::create()->withTransport($transport));

        return $transport;
    }
}
