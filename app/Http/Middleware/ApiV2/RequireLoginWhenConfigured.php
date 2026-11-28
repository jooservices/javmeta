<?php

declare(strict_types=1);

namespace App\Http\Middleware\ApiV2;

use App\Http\Api\V2\ProblemException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The installation setting ui_security.login_required (default: on) keeps the whole UI and its data behind the owner's
 * sign-in, as the legacy UI bridge does. While it is on, a guest may only reach what is needed to sign in (the contract, the
 * session endpoints, the password reset) and may still ask for download links (the owner's decision). Turning it off opens
 * every public (guest) operation.
 */
final class RequireLoginWhenConfigured
{
    private const OPEN_TO_GUESTS = ['api/v2/openapi.json', 'api/v2/sessions', 'api/v2/sessions/current', 'api/v2/password-resets', 'api/v2/password-resets/*', 'api/v2/download-requests', 'api/v2/download-requests/*'];

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('javmeta.ui_login_required', true) && $request->user('web') === null && ! $request->is(...self::OPEN_TO_GUESTS)) {
            throw ProblemException::unauthorized();
        }

        return $next($request);
    }
}
