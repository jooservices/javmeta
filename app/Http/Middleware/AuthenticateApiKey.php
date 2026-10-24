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

    /** The scope a request needs, from the first path segment after /api/v1. */
    private function scopeOf(Request $request): ?string
    {
        $segment = explode('/', ltrim((string) preg_replace('#^/?api/v1/#', '', ltrim($request->path(), '/')), '/'))[0];

        return match (true) {
            $segment === 'movies:bulk' => 'bulk',
            $segment === 'movies' => 'movies',
            $segment === 'performers' => 'performers',
            $segment === 'meta', $segment === 'suggest' => 'catalog',
            $segment === 'status' => 'status',
            $segment === 'mcp' => 'mcp',
            default => null,
        };
    }
}
