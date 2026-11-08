<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies\Probes;

use App\Services\Dependencies\Probes\ElasticsearchProbe;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ElasticsearchProbeTest extends TestCase
{
    public function test_it_checks_cluster_health_with_a_short_timeout(): void
    {
        Http::preventStrayRequests();
        $host = rtrim(fake()->url(), '/');
        config()->set([
            'elasticsearch.host' => $host,
            'elasticsearch.username' => 'javmeta-test-user',
            'elasticsearch.password' => 'javmeta-test-password',
        ]);
        config()->set('elasticsearch.verify_ssl', true);
        Http::fake([$host . '/_cluster/health' => Http::response(['status' => 'green'])]);

        self::assertTrue(app(ElasticsearchProbe::class)->probe());
        Http::assertSent(static fn(Request $request): bool => $request->url() === $host . '/_cluster/health'
            && $request->hasHeader('Authorization', 'Basic ' . base64_encode('javmeta-test-user:javmeta-test-password')));
    }

    public function test_it_reports_a_non_successful_cluster_health_response_as_down(): void
    {
        Http::preventStrayRequests();
        $host = rtrim(fake()->url(), '/');
        config()->set([
            'elasticsearch.host' => $host,
            'elasticsearch.api_key' => 'test-encoded-api-key',
        ]);
        Http::fake([$host . '/_cluster/health' => Http::response([], 503)]);

        self::assertFalse(app(ElasticsearchProbe::class)->probe());
        Http::assertSent(static fn(Request $request): bool => $request->hasHeader('Authorization', 'ApiKey test-encoded-api-key'));
    }
}
