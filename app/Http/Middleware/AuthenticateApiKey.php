<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ForbiddenScopeException;
use App\Exceptions\UnauthorizedException;
use App\Services\Auth\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateApiKey
{
    public function __construct(private ApiKeyService $apiKeyService) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        // The signed-in owner's UI calls the API in-process through /ui-api (see UiApiController): no key needed there.
        if ($request->attributes->get('javmeta.internal_ui') === true) {
            return $next($request);
        }

        $plain = $request->headers->get('X-API-Key', '');
        $apiKey = $this->apiKeyService->findActiveByPlaintext($plain);

        if ($apiKey === null) {
            throw new UnauthorizedException();
        }

        $scope = $this->scopeOf($request);
        if ($scope !== null && ! $apiKey->allows($scope)) {
            throw new ForbiddenScopeException($scope);
        }

        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }

    /** Authorize the router's template, never the client-controlled encoded path. */
    private function scopeOf(Request $request): ?string
    {
        $uri = $request->route()?->uri();

        return match ($uri) {
            'api/v1/auth/verify' => null,
            'api/v1/movies:bulk' => 'bulk',
            'api/v1/movies', 'api/v1/movies/{code}', 'api/v1/movies/calendar',
            'api/v1/movies/facets', 'api/v1/movies/{code}/related', 'api/v1/search', 'api/v1/search/parse', 'api/v1/scenes/search' => 'movies',
            'api/v1/performers', 'api/v1/performers/{id}', 'api/v1/performers/facets' => 'performers',
            'api/v1/meta/genres', 'api/v1/meta/catalog/{kind}', 'api/v1/suggest' => 'catalog',
            'api/v1/status/summary', 'api/v1/status/sources' => 'status',
            'api/v1/config/browser-services' => 'config',
            'api/v1/mcp' => 'mcp',
            'api/v1/media/config', 'api/v1/media/jobs/next', 'api/v1/media/jobs/{id}/complete', 'api/v1/media/jobs/{id}/fail' => 'media',
            default => throw new ForbiddenScopeException('undeclared'),
        };
    }
}
