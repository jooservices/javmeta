<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleGuestUiApi
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->attributes->get('javmeta.guest_mode', false)) {
            return $next($request);
        }

        $limit = max(1, (int) config('javmeta.ui_guest_rate_limit_per_minute', 120));
        $key = 'ui-guest:' . hash('sha256', (string) ($request->ip() ?? 'unknown'));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'type' => 'rate_limited',
                'title' => 'Quá nhiều yêu cầu',
                'status' => 429,
                'detail' => 'Đã đạt giới hạn truy cập giao diện khách.',
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
