<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The legacy UI bridges (/ui-api, /ui-me, /ui-admin) keep working unchanged while the new UI uses /api/v2. They only gain
 * the deprecation signals of RFC 9745 and RFC 8594: Deprecation, a Link to the successor contract and, once the owner has
 * fixed a date (javmeta.legacy_ui_sunset, an HTTP date), Sunset. No date is invented here.
 */
final class MarkLegacyUiDeprecated
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('ui-api', 'ui-api/*', 'ui-me', 'ui-me/*', 'ui-admin', 'ui-admin/*')) {
            return $response;
        }

        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Link', '</api/v2/openapi.json>; rel="successor-version"', false);

        $sunset = config('javmeta.legacy_ui_sunset');
        if (is_string($sunset) && $sunset !== '') {
            $response->headers->set('Sunset', $sunset);
        }

        return $response;
    }
}
