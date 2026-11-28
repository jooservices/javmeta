<?php

declare(strict_types=1);

namespace App\Http\Middleware\ApiV2;

use App\Http\Api\V2\ProblemException;
use App\Http\Api\V2\Scope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Guards a v2 route by scope: a guest gets 401, a signed-in user without the scope gets 403. */
final class RequireScope
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $needed): Response
    {
        $have = Scope::forUser($request->user('web'));
        $want = Scope::from($needed);

        if (! $have->allows($want)) {
            throw $have === Scope::Guest
                ? ProblemException::unauthorized()
                : ProblemException::forbidden('forbidden', "This resource needs {$want->value} access.");
        }

        return $next($request);
    }
}
