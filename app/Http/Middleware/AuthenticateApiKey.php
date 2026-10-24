<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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

        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }
}
