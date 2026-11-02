<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class EnsureUiOwnerOrGuest
{
    public function __construct(
        private StartSession $startSession,
        private EnsureSuperAdministrator $ensureSuperAdministrator,
        private ExceptionHandler $exceptions,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $this->handleRequest($request, $next);
        } catch (Throwable $exception) {
            if (! $request->attributes->get('javmeta.guest_mode', false)) {
                throw $exception;
            }

            report($exception);
            $response = $this->exceptions->render($request, $exception);

            if ($response->isServerError()) {
                $response = $request->expectsJson()
                    ? response()->json(['message' => 'Internal Server Error.'], 500)
                    : response('Internal Server Error.', 500);
            }

            return $this->guestResponse($response);
        }
    }

    /** @param Closure(Request): Response $next */
    private function handleRequest(Request $request, Closure $next): Response
    {
        $required = (bool) config('javmeta.ui_login_required', true);
        $hasSessionCookie = $request->cookies->has((string) config('session.cookie'));

        if (! $required && ! $hasSessionCookie && $request->user() === null) {
            $request->attributes->set('javmeta.guest_mode', true);

            return $this->guestResponse($next($request));
        }

        $response = $this->startSession->handle($request, function (Request $request) use ($required, $next): Response {
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

        if (! $required && $request->attributes->get('javmeta.guest_mode', false)) {
            return $this->guestResponse($response);
        }

        return $response;
    }

    private function guestResponse(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->remove('Set-Cookie');

        return $response;
    }
}
