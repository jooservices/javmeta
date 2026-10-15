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
}
