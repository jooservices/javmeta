<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\UnauthorizedException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OwnerAdminGuard
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('javmeta_auth.owner_admin_token', '');
        $provided = (string) $request->headers->get('X-Owner-Token', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            throw new UnauthorizedException('Owner admin token is missing or invalid.');
        }

        return $next($request);
    }
}
