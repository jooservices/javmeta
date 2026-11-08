<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies\Probes;

use App\Services\Dependencies\Probes\EmbedderProbe;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Tests\TestCase;

final class EmbedderProbeTest extends TestCase
{
    public function test_it_checks_the_embedder_health_endpoint(): void
    {
        Http::preventStrayRequests();
        $url = rtrim(fake()->url(), '/');
        config()->set('elasticsearch.embedder_url', $url);
        Http::fake([$url . '/healthz' => Http::response(['status' => 'ok'])]);

        self::assertTrue((new EmbedderProbe())->probe());
        Http::assertSent(static fn(Request $request): bool => $request->url() === $url . '/healthz');
    }

    public function test_it_reports_a_failed_embedder_health_response_as_down(): void
    {
        Http::preventStrayRequests();
        $url = rtrim(fake()->url(), '/');
        config()->set('elasticsearch.embedder_url', $url);
        Http::fake([$url . '/healthz' => Http::response([], 503)]);

        self::assertFalse((new EmbedderProbe())->probe());
    }

    public function test_it_reports_disabled_embedding_as_not_applicable(): void
    {
        config(['elasticsearch.embedder_enabled' => false]);
        Http::fake();

        self::assertNull((new EmbedderProbe())->check()->available);
        self::assertTrue((new EmbedderProbe())->probe());
        Http::assertNothingSent();

        config(['elasticsearch.embedder_enabled' => true, 'elasticsearch.enabled' => false]);
        self::assertNull((new EmbedderProbe())->check()->available);
    }

    public function test_it_reports_a_transport_failure_or_a_zero_timeout_as_down(): void
    {
        config(['elasticsearch.enabled' => true, 'elasticsearch.embedder_enabled' => true]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(static fn() => throw new ConnectionException('refused'));
        self::assertFalse((new EmbedderProbe())->check()->available);
        self::assertFalse((new EmbedderProbe())->probe());

        config(['javmeta_health.startup.timeout_seconds' => 0]);
        Http::fake();
        self::assertFalse((new EmbedderProbe())->check()->available);
    }
}
