<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use App\Observability\ObservabilityEmitter;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ObservabilityEmitterTest extends TestCase
{
    public function test_emit_fetch_sends_crawlerx_attempts_without_error_text(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
        ]);
        Http::fake(['openobserve.test/*' => Http::response([], 200)]);
        $errorText = fake()->url();

        app(ObservabilityEmitter::class)->emitFetch('javdb', 'detail', false, 1200, 'network', [
            ['method' => 'http', 'elapsed_ms' => 300, 'status' => 503, 'challenge' => false, 'ok' => false, 'error' => $errorText],
            ['method' => 'playwright', 'elapsed_ms' => 900, 'status' => 403, 'challenge' => true, 'ok' => false],
        ]);

        Http::assertSent(static function (ClientRequest $request) use ($errorText): bool {
            $record = $request->data()[0] ?? [];

            return ($record['event'] ?? null) === 'crawl_fetch'
                && ($record['attempt_count'] ?? null) === 2
                && ($record['attempts'][0] ?? null) === ['method' => 'http', 'elapsed_ms' => 300, 'status' => 503, 'challenge' => false, 'ok' => false]
                && ($record['attempts'][1]['challenge'] ?? null) === true
                && ! str_contains($request->body(), $errorText);
        });
    }

    public function test_emit_fetch_carries_the_page_address_reason_status_and_method_without_secrets(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
        ]);
        Http::fake(['openobserve.test/*' => Http::response([], 200)]);

        app(ObservabilityEmitter::class)->emitFetch('missav', 'listing', false, 2500, 'blocked', [
            ['method' => 'http', 'elapsed_ms' => 300, 'status' => 403, 'challenge' => true, 'ok' => false],
            ['method' => 'flaresolverr', 'elapsed_ms' => 2200, 'status' => 403, 'challenge' => true, 'ok' => false],
        ], [
            'url' => 'https://missav.test/en/new?token=secret&page=2',
            'error_message' => 'Blocked: Authorization: Bearer abc123',
            'retryable' => true,
            'retry_after_seconds' => 60,
            'crawlerx_version' => '1.4.1',
        ]);

        Http::assertSent(static function (ClientRequest $request): bool {
            $record = $request->data()[0] ?? [];

            return ($record['page_url'] ?? null) === 'https://missav.test/en/new'
                && ($record['http_status'] ?? null) === 403
                && ($record['method'] ?? null) === 'flaresolverr'
                && ($record['retryable'] ?? null) === true
                && ($record['retry_after_seconds'] ?? null) === 60
                && ($record['crawlerx_version'] ?? null) === '1.4.1'
                && str_contains((string) ($record['error_message'] ?? ''), '[redacted]')
                && ! str_contains($request->body(), 'abc123')
                && ! str_contains($request->body(), 'secret');
        });
    }

    public function test_a_crawl_event_keeps_the_cleaned_page_url_and_message(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
        ]);
        Http::fake(['openobserve.test/*' => Http::response([], 200)]);

        app(ObservabilityEmitter::class)->emitCrawlEvent('javbtc', 'parse_drift', [
            'reason' => 'no product code',
            'page_url' => 'https://javbtc.test/r18/x?session=abc',
            'error_message' => 'token=zzz bad page',
        ]);

        Http::assertSent(static function (ClientRequest $request): bool {
            $detail = ($request->data()[0] ?? [])['detail'] ?? [];

            return ($detail['page_url'] ?? null) === 'https://javbtc.test/r18/x'
                && ! str_contains($request->body(), 'zzz')
                && ($detail['reason'] ?? null) === 'no product code';
        });
    }
}
