<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\MarkLegacyUiDeprecated;
use App\Http\Middleware\RequiresDependency;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\StartupHealthMiddleware;
use App\Observability\InfrastructureOutage;
use App\Http\Api\V2\ProblemRenderer;
use App\Http\Middleware\ApiV2\RequestId;
use App\Http\Middleware\ApiV2\RequireScope;
use App\Http\Middleware\ApiV2\VerifyCsrf;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['requires' => RequiresDependency::class, 'scope' => RequireScope::class]);
        // The UI API (v2) keeps the signed-in user in the web session and checks a CSRF header on unsafe requests.
        $middleware->group('api.v2', [RequestId::class, EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, VerifyCsrf::class]);
        // A new node has no browser session: its token is the credential for this one call.
        $middleware->validateCsrfTokens(except: ['node/enroll']);
        $middleware->appendToGroup('web', SetLocale::class);
        $middleware->appendToGroup('web', MarkLegacyUiDeprecated::class);
        $middleware->appendToGroup('api', SetLocale::class);
        if (! app()->runningUnitTests()) {
            $middleware->prepend(StartupHealthMiddleware::class);
        }
        // JavMeta sits behind the gatekeeper (HAProxy) that terminates TLS and then nginx. HAProxy overwrites
        // X-Forwarded-Proto, so redirects, signed URLs and the Secure session cookie can use https. The client
        // address is NOT taken from X-Forwarded-For: nginx already rewrites REMOTE_ADDR from the address HAProxy
        // appended (set_real_ip_from + real_ip_header), and trusting the header again with `*` would let any
        // visitor choose its own IP (login throttle and every IP limiter bypass). X-Forwarded-Host/-Port are not
        // trusted either: nginx forwards the original Host header.
        $middleware->trustProxies(at: '*', headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportWhen(InfrastructureOutage::matches(...));
        Integration::handles($exceptions);
        $exceptions->render(static fn(Throwable $exception, Request $request) => ProblemRenderer::handles($request) ? (new ProblemRenderer())->render($exception, $request) : null);
    })->create();
