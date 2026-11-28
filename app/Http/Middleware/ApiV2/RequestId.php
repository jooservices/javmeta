<?php

declare(strict_types=1);

namespace App\Http\Middleware\ApiV2;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Gives every v2 request a trace id and echoes it in X-Request-Id. */
final class RequestId
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) ($request->headers->get('X-Request-Id') ?: Str::lower(Str::random(12)));
        $request->attributes->set('trace_id', $id);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
