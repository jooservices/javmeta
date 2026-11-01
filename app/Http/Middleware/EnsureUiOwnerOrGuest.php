<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureUiOwnerOrGuest
{
    public function __construct(
        private StartSession $startSession,
        private EnsureSuperAdministrator $ensureSuperAdministrator,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $required = (bool) config('javmeta.ui_login_required', true);
        $hasSessionCookie = $request->cookies->has((string) config('session.cookie'));

        if (! $required && ! $hasSessionCookie && $request->user() === null) {
            $request->attributes->set('javmeta.guest_mode', true);

            return $next($request);
        }

        return $this->startSession->handle($request, function (Request $request) use ($required, $next): Response {
            if ($request->user() === null) {
                if ($required) {
                    throw new AuthenticationException('Unauthenticated.', ['web'], route('login'));
                }

                $request->attributes->set('javmeta.guest_mode', true);

                return $next($request);
            }

            $request->attributes->set('javmeta.guest_mode', false);

            return $this->ensureSuperAdministrator->handle($request, $next);
        });
    }
}
