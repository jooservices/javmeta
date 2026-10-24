<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only bridge for the UI: `/ui-api/<path>` runs `/api/v1/<path>` inside the same process for the signed-in owner,
 * so the browser never holds an API key and the UI uses exactly the public API contract.
 */
final class UiApiController
{
    /** Paths the UI may read (GET only). */
    private const ALLOWED = '#^(movies|performers|meta|status|suggest|search)(/|$)#';

    public function __invoke(Request $request, string $path): Response
    {
        abort_unless($request->isMethod('GET') && preg_match(self::ALLOWED, $path) === 1, 404);

        $internal = Request::create(
            '/api/v1/' . $path,
            'GET',
            $request->query(),
            [],
            [],
            ['REMOTE_ADDR' => $request->ip() ?? '127.0.0.1', 'HTTP_ACCEPT' => 'application/json'],
        );
        $internal->attributes->set('javmeta.internal_ui', true);

        $response = app('router')->dispatch($internal);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
