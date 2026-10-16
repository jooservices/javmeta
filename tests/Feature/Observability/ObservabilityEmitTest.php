<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Models\CrawlEvent;
use App\Observability\ObservabilityEmitter;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ObservabilityEmitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
            'openobserve.trace_sample_rate' => 1.0,
        ]);
        Http::fake([
            'openobserve.test/*' => Http::response(['code' => 200], 200),
        ]);
    }

    public function test_crawl_event_emit_is_sanitized(): void
    {
        $title = fake()->sentence(4);
        app(ObservabilityEmitter::class)->emitCrawlEvent('javdb', CrawlEvent::KIND_BLOCKED, [
            'error_code' => 'blocked',
            'title' => $title,
            'url' => 'https://example.test/movie',
            'queued' => 1,
        ]);

        Http::assertSent(function ($request) use ($title): bool {
            if (! str_contains($request->url(), '/_json')) {
                return false;
            }
            $body = $request->body();

            return ! str_contains($body, $title)
                && ! str_contains($body, 'https://example.test')
                && str_contains($body, 'crawl_event')
                && str_contains($body, 'blocked');
        });
    }

    public function test_api_usage_emit_includes_safe_fields(): void
    {
        $created = app(ApiKeyService::class)->create(fake()->words(2, true));

        $this->getJson('/api/v1/auth/verify', [
            'X-API-Key' => $created->plaintext,
        ])->assertOk();

        $recorded = collect(Http::recorded())->map(fn($pair) => [
            'url' => $pair[0]->url(),
            'body' => $pair[0]->body(),
        ])->all();

        $this->assertNotEmpty($recorded, 'expected OpenObserve HTTP calls');

        $matched = false;
        foreach ($recorded as $item) {
            $decoded = json_decode($item['body'], true);
            if (! is_array($decoded)) {
                continue;
            }
            $payload = array_key_exists(0, $decoded) ? ($decoded[0] ?? []) : $decoded;
            if (! is_array($payload)) {
                continue;
            }
            if (
                ($payload['event'] ?? null) === 'api_usage'
                && ($payload['endpoint'] ?? null) === '/api/v1/auth/verify'
                && (int) ($payload['api_key_id'] ?? 0) === (int) $created->model->getKey()
                && ! str_contains($item['body'], $created->plaintext)
            ) {
                $matched = true;
                break;
            }
        }

        $this->assertTrue($matched, 'api_usage payload missing; recorded=' . json_encode($recorded));
    }
}
