<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Health\StartupHealthService;
use App\Services\Health\StartupHealthSnapshotStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class StartupHealthMiddleware
{
    public function __construct(
        private StartupHealthService $health,
        private StartupHealthSnapshotStore $snapshots,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/health', 'api/health/live')) {
            return $next($request);
        }

        $report = $this->snapshots->get();
        if ($report === null) {
            $report = $this->health->run('web', 0, false);
            $this->snapshots->put($report, (int) config('javmeta_health.startup.snapshot_ttl_seconds', 5));
        }

        if (! (bool) ($report['blocking'] ?? true)) {
            return $next($request);
        }

        $retryAfter = max(1, (int) config('javmeta_health.startup.snapshot_ttl_seconds', 5));
        $failed = array_values(array_filter((array) ($report['checks'] ?? []), static fn(array $row): bool => ($row['class'] ?? null) === 'primary' && ($row['status'] ?? null) === 'fail'));
        $details = array_map(static fn(array $row): array => [
            'service' => $row['service'],
            'message' => $row['message'],
        ], $failed);

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'status' => 'unavailable',
                'message' => 'A primary service is unavailable.',
                'checks' => $details,
            ], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => (string) $retryAfter]);
        }

        return response('<!doctype html><html lang="vi"><meta charset="utf-8"><title>Dịch vụ tạm thời chưa sẵn sàng</title><h1>Dịch vụ tạm thời chưa sẵn sàng</h1><p>Vui lòng thử lại sau.</p></html>', Response::HTTP_SERVICE_UNAVAILABLE, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Retry-After' => (string) $retryAfter,
        ]);
    }
}
