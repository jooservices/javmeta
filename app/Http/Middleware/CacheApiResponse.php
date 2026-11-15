<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Api\ApiResponseCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class CacheApiResponse
{
    public function __construct(private ApiResponseCache $cache) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->cache->handleApi($request, $next);
    }
}
