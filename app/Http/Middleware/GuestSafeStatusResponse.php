<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class GuestSafeStatusResponse
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ((bool) config('javmeta.ui_login_required', true) || ! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);
        if (is_array($payload) && is_array($payload['data'] ?? null)) {
            $payload['data'] = array_intersect_key(
                $payload['data'],
                array_flip(['status', 'message', 'timestamp']),
            );
            $response->setData($payload);
        }

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
