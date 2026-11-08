<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MovieSearchRequest;
use App\Services\Performance\PerformanceCache;
use Illuminate\Http\JsonResponse;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

/** What the movies of a filter have in common (genres, makers, series, labels, years, sources, performers) with counts. */
final class MovieFacetsController extends BaseApiController
{
    public function index(MovieSearchRequest $request, PerformanceCache $cache): JsonResponse
    {
        $filters = $request->filters();
        $data = $cache->facets($filters);

        return $this->respondWithData(data: $data, message: 'Facets retrieved successfully.');
    }
}
