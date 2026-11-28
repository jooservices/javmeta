<?php

declare(strict_types=1);

namespace App\Http\Middleware\ApiV2;

use App\Http\Api\V2\ProblemException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A signed-in user's unsafe request must carry the session's CSRF token in X-CSRF-Token. */
final class VerifyCsrf
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->mustCheck($request)) {
            $token = (string) $request->headers->get('X-CSRF-Token', '');

            if ($token === '' || ! hash_equals((string) $request->session()->token(), $token)) {
                throw ProblemException::forbidden('csrf_mismatch', 'Missing or wrong CSRF token.');
            }
        }

        return $next($request);
    }

    private function mustCheck(Request $request): bool
    {
        return ! $request->isMethodSafe() && $request->user('web') !== null;
    }
}
