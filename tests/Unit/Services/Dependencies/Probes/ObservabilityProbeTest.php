<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies\Probes;

use App\Services\Dependencies\Probes\ObservabilityProbe;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Tests\TestCase;

final class ObservabilityProbeTest extends TestCase
{
    public function test_it_checks_openobserve_health_when_enabled(): void
    {
        Http::preventStrayRequests();
        $url = rtrim(fake()->url(), '/');
        config()->set('openobserve.enabled', true);
        config()->set('openobserve.url', $url);
        Http::fake([$url . '/healthz' => Http::response(['status' => 'ok'])]);

        self::assertTrue((new ObservabilityProbe())->probe());
        Http::assertSent(static fn(Request $request): bool => $request->url() === $url . '/healthz');
    }

    public function test_disabled_openobserve_is_treated_as_available(): void
    {
        Http::preventStrayRequests();
        config()->set('openobserve.enabled', false);

        self::assertTrue((new ObservabilityProbe())->probe());
        Http::assertNothingSent();
    }

    public function test_an_unhealthy_answer_or_a_transport_failure_is_down(): void
    {
        config(['openobserve.enabled' => true, 'openobserve.url' => 'http://oo.example.test:5080/']);
        Http::fake(['oo.example.test:5080/*' => Http::response('', 500)]);
        self::assertFalse((new ObservabilityProbe())->check()->available);
        self::assertFalse((new ObservabilityProbe())->probe());

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(static fn() => throw new ConnectionException('refused'));
        self::assertFalse((new ObservabilityProbe())->check()->available);
    }
}
