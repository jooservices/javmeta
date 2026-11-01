<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Http\Middleware\StartupHealthMiddleware;
use App\Services\Health\StartupHealthSnapshotStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\StartupHealthTestFixture;
use Tests\TestCase;

final class StartupHealthMiddlewareTest extends TestCase
{
    public function test_it_returns_json_503_and_retry_after_for_a_red_primary_snapshot(): void
    {
        $fixture = StartupHealthTestFixture::bind(['postgres' => false]);
        $path = storage_path('framework/testing/startup-middleware-' . bin2hex(random_bytes(5)) . '.json');
        $middleware = new StartupHealthMiddleware($fixture['health'], new StartupHealthSnapshotStore($path));

        try {
            $response = $middleware->handle(
                Request::create('/app', 'GET', server: ['HTTP_ACCEPT' => 'application/json']),
                static fn(): Response => new Response('next'),
            );

            self::assertSame(503, $response->getStatusCode());
            self::assertSame('5', $response->headers->get('Retry-After'));
            self::assertSame('postgres', json_decode((string) $response->getContent(), true)['checks'][0]['service']);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_returns_html_for_browser_requests_and_bypasses_health_paths(): void
    {
        $fixture = StartupHealthTestFixture::bind(['postgres' => false]);
        $path = storage_path('framework/testing/startup-middleware-' . bin2hex(random_bytes(5)) . '.json');
        $middleware = new StartupHealthMiddleware($fixture['health'], new StartupHealthSnapshotStore($path));

        try {
            $html = $middleware->handle(Request::create('/app'), static fn(): Response => new Response('next'));
            self::assertSame(503, $html->getStatusCode());
            self::assertStringContainsString('text/html', (string) $html->headers->get('Content-Type'));

            $before = $fixture['probes']['postgres']->calls;
            $live = $middleware->handle(Request::create('/api/health/live'), static fn(): Response => new Response('live'));
            self::assertSame(200, $live->getStatusCode());
            self::assertSame($before, $fixture['probes']['postgres']->calls);
        } finally {
            @unlink($path);
        }
    }

    public function test_cached_snapshot_is_reused_until_ttl_expires(): void
    {
        $fixture = StartupHealthTestFixture::bind(['postgres' => false]);
        $now = 100;
        $path = storage_path('framework/testing/startup-middleware-' . bin2hex(random_bytes(5)) . '.json');
        $store = new StartupHealthSnapshotStore($path, static function () use (&$now): int {
            return $now;
        });
        $middleware = new StartupHealthMiddleware($fixture['health'], $store);
        $next = static fn(): Response => new Response('next');

        try {
            self::assertSame(503, $middleware->handle(Request::create('/app'), $next)->getStatusCode());
            $calls = $fixture['probes']['postgres']->calls;
            $fixture['probes']['postgres']->setResults(true);
            self::assertSame(503, $middleware->handle(Request::create('/app'), $next)->getStatusCode());
            self::assertSame($calls, $fixture['probes']['postgres']->calls);

            $now += 6;
            self::assertSame(200, $middleware->handle(Request::create('/app'), $next)->getStatusCode());
            self::assertGreaterThan($calls, $fixture['probes']['postgres']->calls);
        } finally {
            @unlink($path);
        }
    }
}
